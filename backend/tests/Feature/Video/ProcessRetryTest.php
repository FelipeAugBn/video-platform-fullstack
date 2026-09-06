<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Application\StartProcessing\StartProcessing;
use App\Video\Domain\ProcessingEventId;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Queue\ProcessVideoJob;
use App\Video\Infrastructure\Simulator\CallbackDelivery;
use App\Video\Infrastructure\Simulator\SimulatorDeliveryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\EncaminhaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * Retomada do processamento e entrega duplicada (plan §§8.6, 13.2, 13.4).
 *
 * As tres afirmacoes aqui sao as que sustentam a tolerancia a repeticao fora da
 * requisicao HTTP — a que nao depende de "acontecer so uma vez", porque essa
 * garantia nao existe:
 *
 *   1. o job repetido pela fila **retoma** em vez de encerrar ou regredir;
 *   2. a entrega duplicada carrega o mesmo `event_id` e **nao produz efeito
 *      novo**;
 *   3. o job esgotado termina sem tocar no estado do video — provado em
 *      `SimulatorFailedJobTest`, que executa o consumidor de verdade sobre a
 *      Database Queue e consulta `failed_jobs`. Aqui isso nao seria provavel:
 *      chamar o componente tres vezes numa linha de execucao exercita o
 *      componente, e nao a fila.
 */
final class ProcessRetryTest extends TestCase
{
    use CatalogoDeTeste;
    use EncaminhaCallback;
    use RefreshDatabase;
    use VideoDeTeste;

    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);

        $aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
        $this->videoId = $this->umaTentativa($aula, VideoState::PROCESSING)->id();
    }

    public function test_o_job_repetido_retoma_e_entrega_de_novo(): void
    {
        $this->encaminharCallbacksParaAAplicacao();

        // O cenario e o retry apos o commit e antes do reconhecimento: a
        // tentativa ja esta em `processing`, e o job volta.
        $this->processar();
        $this->entregarPendente();

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
        ]);
    }

    public function test_a_entrega_duplicada_do_mesmo_evento_nao_produz_efeito_adicional(): void
    {
        $this->encaminharCallbacksParaAAplicacao();

        $this->entregarPendente();
        $referencia = DB::table('video_attempts')->where('id', $this->videoId)->value('playback_reference');

        // A segunda entrega — vinda de um retry do job ou de uma reentrega do
        // proprio simulador — carrega o mesmo `event_id`.
        $this->entregarPendente();

        $this->assertSame([200, 200], array_column($this->callbacksEncaminhados, 'status'));

        // Um unico desfecho registrado, e o efeito preservado.
        $this->assertSame(
            1,
            DB::table('webhook_events')
                ->where('event_id', ProcessingEventId::para($this->videoId, ProcessingScenario::SUCCESS))
                ->count(),
        );

        $this->assertSame(
            $referencia,
            DB::table('video_attempts')->where('id', $this->videoId)->value('playback_reference'),
        );
    }

    private function processar(): void
    {
        (new ProcessVideoJob($this->videoId))->handle($this->app->make(StartProcessing::class));
    }

    private function entregarPendente(): void
    {
        (new SimulatorDeliveryJob($this->videoId, ProcessingScenario::SUCCESS))
            ->handle($this->app->make(CallbackDelivery::class));
    }
}
