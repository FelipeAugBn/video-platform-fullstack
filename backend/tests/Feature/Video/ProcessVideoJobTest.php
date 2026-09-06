<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Catalog\Domain\Lesson;
use App\Models\User;
use App\Video\Application\StartProcessing\StartProcessing;
use App\Video\Domain\ProcessingEventId;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Queue\ProcessVideoJob;
use App\Video\Infrastructure\Simulator\SimulatorDeliveryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * Os caminhos do `ProcessVideoJob` e a estabilidade do `event_id` (plan §13.2).
 *
 * Duas coisas sao provadas aqui, e elas se apoiam uma na outra: o job decide
 * pela leitura travada do estado, e a entrega que ele agenda carrega sempre o
 * mesmo identificador. Sem a segunda, a retomada da primeira transformaria cada
 * repeticao num evento novo e anularia a idempotencia inteira do webhook.
 *
 * A fila e falsa aqui de proposito — o que se afirma e **contrato**: qual classe
 * foi despachada, para qual fila, com qual identificador. Que a linha de `jobs`
 * compartilha o commit com a mudanca de estado e outra afirmacao, e ela tem
 * teste proprio contra o MySQL real (`UploadQueueAtomicityTest`).
 */
final class ProcessVideoJobTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private Lesson $aula;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
    }

    // -----------------------------------------------------------------------
    // Os cinco estados de entrada
    // -----------------------------------------------------------------------

    public function test_em_uploaded_transiciona_para_processing_e_agenda_a_entrega(): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->aula, VideoState::UPLOADED);

        $this->processar($tentativa->id());

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::PROCESSING->value,
        ]);

        Queue::assertPushedOn('simulator', SimulatorDeliveryJob::class);
    }

    public function test_em_processing_retoma_sem_transicionar_e_agenda_de_novo(): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->aula, VideoState::PROCESSING);

        $this->processar($tentativa->id());

        // Nao transiciona: `processing` para `processing` nao e uma transicao, e
        // declarar que fosse apagaria a diferenca entre repetir uma operacao e
        // refazer um efeito.
        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => VideoState::PROCESSING->value,
        ]);

        Queue::assertPushedOn('simulator', SimulatorDeliveryJob::class);
    }

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosQueNaoIniciam(): iterable
    {
        yield 'pending' => [VideoState::PENDING];
        yield 'uploading' => [VideoState::UPLOADING];
        yield 'ready' => [VideoState::READY];
        yield 'failed' => [VideoState::FAILED];
    }

    #[DataProvider('estadosQueNaoIniciam')]
    public function test_estados_incompativeis_e_terminais_encerram_sem_efeito(VideoState $estado): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->aula, $estado);

        $this->processar($tentativa->id());

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => $estado->value,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_tentativa_inexistente_encerra_sem_erro(): void
    {
        Queue::fake();

        // Um retry cuja tentativa sumiu no intervalo. Repetir o job nao a traria
        // de volta, e falhar so encheria `failed_jobs`.
        $this->processar('01936f1a-7c00-7a3e-9b7d-000000000000');

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------------
    // O identificador do evento
    // -----------------------------------------------------------------------

    public function test_o_event_id_e_estavel_entre_execucoes_da_mesma_tentativa(): void
    {
        $id = '01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60';

        $this->assertSame(
            ProcessingEventId::para($id, ProcessingScenario::SUCCESS),
            ProcessingEventId::para($id, ProcessingScenario::SUCCESS),
        );
    }

    public function test_o_event_id_distingue_sucesso_de_falha_na_mesma_tentativa(): void
    {
        $id = '01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60';

        // Se coincidissem, o callback de falha da demonstracao seria descartado
        // como reentrega do callback de sucesso.
        $this->assertNotSame(
            ProcessingEventId::para($id, ProcessingScenario::SUCCESS),
            ProcessingEventId::para($id, ProcessingScenario::FAILURE),
        );
    }

    public function test_o_event_id_distingue_tentativas_diferentes(): void
    {
        $this->assertNotSame(
            ProcessingEventId::para('01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d60', ProcessingScenario::SUCCESS),
            ProcessingEventId::para('01936f1a-7c00-7a3e-9b7d-2f5c8e4a1d61', ProcessingScenario::SUCCESS),
        );
    }

    public function test_a_retomada_reagenda_a_entrega_com_o_mesmo_identificador(): void
    {
        Queue::fake();
        $tentativa = $this->umaTentativa($this->aula, VideoState::UPLOADED);

        // Primeira execucao: inicia. Segunda: encontra `processing` e retoma.
        $this->processar($tentativa->id());
        $this->processar($tentativa->id());

        Queue::assertPushed(SimulatorDeliveryJob::class, 2);

        // As duas entregas sao do mesmo cenario e da mesma tentativa, entao
        // carregam o mesmo `event_id` — e o webhook reconhece a segunda como
        // repeticao da primeira.
        Queue::assertPushed(
            SimulatorDeliveryJob::class,
            fn (SimulatorDeliveryJob $job): bool => $this->identificadorDe($job) === ProcessingEventId::para(
                $tentativa->id(),
                ProcessingScenario::SUCCESS,
            ),
        );
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function processar(string $videoAttemptId): void
    {
        // Pelo `handle` do job, e nao pelo caso de uso direto: e o caminho que a
        // fila executa, e o que se quer provar inclui o job carregar apenas o
        // identificador e recarregar o estado.
        (new ProcessVideoJob($videoAttemptId))->handle($this->app->make(StartProcessing::class));
    }

    private function identificadorDe(SimulatorDeliveryJob $job): string
    {
        $espelho = new \ReflectionObject($job);

        return ProcessingEventId::para(
            (string) $espelho->getProperty('videoAttemptId')->getValue($job),
            $espelho->getProperty('scenario')->getValue($job),
        );
    }
}
