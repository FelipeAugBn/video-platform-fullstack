<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Catalog\Domain\Module;
use App\Models\User;
use App\Video\Application\Port\Exception\TransientStoreFailure;
use App\Video\Application\Port\WebhookEventStore;
use App\Video\Domain\VideoState;
use App\Video\Domain\WebhookOutcome;
use App\Video\Infrastructure\Persistence\Eloquent\EloquentWebhookEventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\AssinaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * Os desfechos do callback e a idempotencia por `event_id` (RF-WHK-002 a 007,
 * RF-ERR-006 a 008, RF-ERR-014, plan §§8.4, 13.4).
 *
 * Cobre AC-VID-004, AC-VID-005, AC-VID-006, AC-VID-007, AC-VID-009 e AC-VID-013.
 *
 * A organizacao segue a tabela de plan §13.4: aceito, rejeitado em definitivo, e
 * falha transitoria — que e a unica das tres que **nao** deixa linha.
 */
final class WebhookCallbackTest extends TestCase
{
    use AssinaCallback;
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private Module $modulo;

    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulo = $this->umModulo($this->umCurso(User::factory()->producer()->create()));
        $this->videoId = $this->umaTentativa($this->umaAula($this->modulo), VideoState::PROCESSING)->id();
    }

    // -----------------------------------------------------------------------
    // Desfechos aplicados
    // -----------------------------------------------------------------------

    public function test_callback_de_sucesso_leva_o_video_a_ready_com_a_referencia(): void
    {
        $resposta = $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-ok', 'videos/pronto.mp4'));

        $resposta->assertOk();
        $resposta->assertJsonPath('data.outcome', WebhookOutcome::ACCEPTED->value);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
            'playback_reference' => 'videos/pronto.mp4',
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-ok',
            'video_attempt_id' => $this->videoId,
            'outcome' => WebhookOutcome::ACCEPTED->value,
        ]);
    }

    public function test_callback_de_falha_leva_o_video_a_failed_com_mensagem_segura(): void
    {
        $resposta = $this->entregar($this->cargaDeFalha($this->videoId, 'evt-falha'));

        $resposta->assertOk();

        $linha = DB::table('video_attempts')->where('id', $this->videoId)->first();

        $this->assertSame(VideoState::FAILED->value, $linha->state);
        // Codigo e mensagem sao derivados pelo backend, nunca recebidos: a carga
        // oficial nao tem campo de mensagem (RF-WHK-011).
        $this->assertSame('VIDEO_PROCESSING_FAILED', $linha->failure_code);
        $this->assertNotEmpty($linha->failure_message);
        $this->assertNull($linha->playback_reference);
    }

    public function test_todo_evento_concluido_tem_processed_at_preenchido(): void
    {
        $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-instante'))->assertOk();

        // A coluna e obrigatoria e entra no proprio `INSERT` da reserva: nao ha
        // um segundo momento em que preenche-la, e nenhuma linha pode existir
        // sem ela (plan §8.4).
        $this->assertNotNull(
            DB::table('webhook_events')->where('event_id', 'evt-instante')->value('processed_at'),
        );
    }

    // -----------------------------------------------------------------------
    // Reentrega (AC-VID-004, AC-VID-005)
    // -----------------------------------------------------------------------

    public function test_reentrega_do_mesmo_evento_repete_o_desfecho_sem_efeito_novo(): void
    {
        $carga = $this->cargaDeSucesso($this->videoId, 'evt-repetido', 'videos/primeira.mp4');

        $this->entregar($carga)->assertOk();
        $this->entregar($carga)->assertOk();

        $this->assertSame(1, DB::table('webhook_events')->where('event_id', 'evt-repetido')->count());
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'playback_reference' => 'videos/primeira.mp4',
        ]);
    }

    public function test_reentrega_com_corpo_diferente_nao_aplica_o_corpo_recebido(): void
    {
        $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-corpo', 'videos/primeira.mp4'))->assertOk();

        // Mesmo `event_id`, conteudo completamente diferente. O desfecho
        // registrado se repete **sem que ninguem olhe o corpo** (RN-IDM-003).
        $this->entregar($this->cargaDeFalha($this->videoId, 'evt-corpo'))->assertOk();

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
            'playback_reference' => 'videos/primeira.mp4',
            'failure_code' => null,
        ]);
    }

    public function test_reentrega_de_rejeicao_permanente_repete_o_409(): void
    {
        $carga = $this->cargaDeFalha('01936f1a-7c00-7a3e-9b7d-000000000000', 'evt-inexistente');

        $this->entregar($carga)->assertStatus(409);
        $this->entregar($carga)->assertStatus(409);

        $this->assertSame(1, DB::table('webhook_events')->where('event_id', 'evt-inexistente')->count());
    }

    // -----------------------------------------------------------------------
    // Rejeicao permanente (AC-VID-006, AC-VID-009)
    // -----------------------------------------------------------------------

    public function test_callback_de_falha_para_video_em_ready_nao_regride(): void
    {
        $pronta = $this->umaTentativa($this->umaAula($this->modulo, 'Aula ja pronta'), VideoState::READY);

        $resposta = $this->entregar($this->cargaDeFalha($pronta->id(), 'evt-tardio'));

        $resposta->assertStatus(409);
        $resposta->assertJsonPath('code', 'WEBHOOK_EVENT_REJECTED');

        $this->assertDatabaseHas('video_attempts', [
            'id' => $pronta->id(),
            'state' => VideoState::READY->value,
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-tardio',
            'outcome' => WebhookOutcome::REJECTED_PERMANENT->value,
        ]);
    }

    /**
     * @return iterable<string, array{VideoState}>
     */
    public static function estadosIncompativeis(): iterable
    {
        yield 'pending' => [VideoState::PENDING];
        yield 'uploading' => [VideoState::UPLOADING];
        yield 'uploaded' => [VideoState::UPLOADED];
        yield 'failed' => [VideoState::FAILED];
    }

    #[DataProvider('estadosIncompativeis')]
    public function test_estado_incompativel_e_rejeitado_em_definitivo(VideoState $estado): void
    {
        $tentativa = $this->umaTentativa($this->umaAula($this->modulo, 'Aula '.$estado->value), $estado);

        $this->entregar($this->cargaDeSucesso($tentativa->id(), 'evt-'.$estado->value))->assertStatus(409);

        $this->assertDatabaseHas('video_attempts', [
            'id' => $tentativa->id(),
            'state' => $estado->value,
        ]);
    }

    public function test_video_id_uuid_valido_sem_tentativa_registra_rejeicao_com_referencia_nula(): void
    {
        $resposta = $this->entregar(
            $this->cargaDeSucesso('01936f1a-7c00-7a3e-9b7d-000000000000', 'evt-orfao'),
        );

        $resposta->assertStatus(409);

        // A reserva existe **sem** chave estrangeira resolvida. Sem ela, o
        // `event_id` ficaria eternamente sem desfecho e o emissor reentregaria
        // sem fim algo que jamais seria aceito (plan §8.4).
        $this->assertDatabaseHas('webhook_events', [
            'event_id' => 'evt-orfao',
            'video_attempt_id' => null,
            'outcome' => WebhookOutcome::REJECTED_PERMANENT->value,
        ]);
    }

    public function test_video_id_que_nao_e_uuid_responde_422_sem_reservar(): void
    {
        // A distincao com o caso acima decide se o emissor reentrega para sempre
        // ou para de vez: carga malformada e defeito do emissor, e nao pode
        // consumir uma reserva de idempotencia.
        $resposta = $this->entregar($this->cargaDeSucesso('nao-e-uuid', 'evt-malformado'));

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('webhook_events', 0);
    }

    // -----------------------------------------------------------------------
    // Carga estruturalmente invalida (plan §8.4)
    // -----------------------------------------------------------------------

    public function test_status_desconhecido_responde_422_sem_reservar(): void
    {
        $this->entregar([
            'event_id' => 'evt-status',
            'video_id' => $this->videoId,
            'status' => 'quase-pronto',
            'playback_reference' => null,
        ])->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_sucesso_sem_referencia_responde_422(): void
    {
        // Um `ready` sem referencia produziria uma aula publicavel e
        // inassistivel.
        $this->entregar([
            'event_id' => 'evt-sem-ref',
            'video_id' => $this->videoId,
            'status' => 'ready',
            'playback_reference' => null,
        ])->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_falha_com_referencia_responde_422(): void
    {
        // Recusa ativa, e nao tolerancia: aceitar e ignorar deixaria a
        // incoerencia do emissor passar sem que nada a denunciasse.
        $this->entregar([
            'event_id' => 'evt-falha-com-ref',
            'video_id' => $this->videoId,
            'status' => 'failed',
            'playback_reference' => 'videos/algo.mp4',
        ])->assertStatus(422);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    // -----------------------------------------------------------------------
    // Falha transitoria (AC-VID-013, RN-VID-007)
    // -----------------------------------------------------------------------

    public function test_falha_transitoria_responde_503_com_retry_after_e_nao_registra_desfecho(): void
    {
        config(['logging.default' => 'null']);
        $this->comFalhaTransitoriaNoRegistro();

        $resposta = $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-transitorio'));

        $resposta->assertStatus(503);
        $resposta->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
        $resposta->assertHeader('Retry-After');

        // Nada registrado e nada aplicado: a transacao inteira foi desfeita.
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::PROCESSING->value,
        ]);
    }

    public function test_a_reentrega_apos_a_falha_transitoria_e_aplicada_normalmente(): void
    {
        config(['logging.default' => 'null']);
        $registro = $this->comFalhaTransitoriaNoRegistro();
        $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-transitorio'))->assertStatus(503);

        // A infraestrutura volta ao normal, e o mesmo `event_id` e avaliado do
        // zero — porque a reserva nunca chegou a existir.
        $registro->falhar = false;

        $this->entregar($this->cargaDeSucesso($this->videoId, 'evt-transitorio'))->assertOk();

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Substitui a porta do registro por uma que falha ao gravar o desfecho.
     *
     * A falha e produzida **depois** da reserva e da transicao, que e o pior
     * momento possivel: e o que prova que o rollback desfaz tudo, e nao apenas o
     * que ainda nao tinha sido escrito.
     */
    private function comFalhaTransitoriaNoRegistro(): WebhookEventStore
    {
        $registro = new class($this->app->make(EloquentWebhookEventStore::class)) implements WebhookEventStore
        {
            public bool $falhar = true;

            public function __construct(private readonly WebhookEventStore $real) {}

            public function reserve(string $eventId, string $receivedVideoId, string $receivedStatus): bool
            {
                return $this->real->reserve($eventId, $receivedVideoId, $receivedStatus);
            }

            public function outcomeOf(string $eventId): ?WebhookOutcome
            {
                return $this->real->outcomeOf($eventId);
            }

            public function settle(string $eventId, ?string $videoAttemptId, WebhookOutcome $outcome): void
            {
                if ($this->falhar) {
                    throw TransientStoreFailure::em('settle');
                }

                $this->real->settle($eventId, $videoAttemptId, $outcome);
            }
        };

        // O dublê e alternado por um sinalizador em vez de ser substituido no
        // container: o roteador guarda a instancia do controller resolvida na
        // primeira requisicao, entao uma troca de vinculo depois dela nao
        // alcancaria o caso de uso ja construido. Um sinalizador tambem descreve
        // melhor o que se quer dizer — a mesma infraestrutura, que falhou e
        // voltou.
        $this->app->instance(WebhookEventStore::class, $registro);

        return $registro;
    }
}
