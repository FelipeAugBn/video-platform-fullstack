<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Domain\ProcessingEventId;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\EncaminhaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * `php artisan demo:simulate-video-failure` (RF-WHK-008, RF-ERR-004,
 * plan §13.3).
 *
 * A falha de processamento nao e sorteada nem escondida atras de uma convencao
 * no nome do arquivo: ela e provocada por este comando, que usa o **mesmo**
 * componente do simulador, o mesmo HMAC e o mesmo endpoint. O teste exercita
 * exatamente o que a demonstracao exercita.
 */
final class SimulateFailureTest extends TestCase
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
        $this->encaminharCallbacksParaAAplicacao();
    }

    public function test_o_comando_leva_o_video_a_failed(): void
    {
        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $this->videoId])
            ->assertSuccessful();

        $linha = DB::table('video_attempts')->where('id', $this->videoId)->first();

        $this->assertSame(VideoState::FAILED->value, $linha->state);
        $this->assertSame('VIDEO_PROCESSING_FAILED', $linha->failure_code);
        $this->assertNotEmpty($linha->failure_message);
        // `failed` nao carrega referencia de reproducao.
        $this->assertNull($linha->playback_reference);
    }

    public function test_o_comando_usa_o_event_id_do_cenario_de_falha(): void
    {
        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $this->videoId])
            ->assertSuccessful();

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => ProcessingEventId::para($this->videoId, ProcessingScenario::FAILURE),
            'video_attempt_id' => $this->videoId,
            'outcome' => 'accepted',
            'received_status' => VideoState::FAILED->value,
        ]);
    }

    public function test_repetir_o_comando_nao_produz_efeito_novo(): void
    {
        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $this->videoId])
            ->assertSuccessful();

        $instante = DB::table('webhook_events')->value('processed_at');

        // A segunda execucao reentrega o **mesmo** evento: o webhook reconhece a
        // repeticao e repete o desfecho registrado. E uma demonstracao de
        // idempotencia que cabe em duas execucoes seguidas.
        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $this->videoId])
            ->assertSuccessful();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame($instante, DB::table('webhook_events')->value('processed_at'));
        $this->assertSame([200, 200], array_column($this->callbacksEncaminhados, 'status'));
    }

    public function test_o_comando_nao_escreve_direto_na_tentativa(): void
    {
        // Tentativa em `pending`: o callback de falha nao e transicao valida a
        // partir dela. Se o comando escrevesse direto, o estado mudaria assim
        // mesmo; como ele passa pelo callback, o evento e rejeitado em definitivo
        // e o video e preservado.
        $aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
        $pendente = $this->umaTentativa($aula, VideoState::PENDING);

        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $pendente->id()])
            ->assertSuccessful();

        $this->assertDatabaseHas('video_attempts', [
            'id' => $pendente->id(),
            'state' => VideoState::PENDING->value,
            'failure_code' => null,
        ]);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => ProcessingEventId::para($pendente->id(), ProcessingScenario::FAILURE),
            'outcome' => 'rejected_permanent',
        ]);
    }

    public function test_tentativa_inexistente_e_registrada_como_rejeicao_permanente(): void
    {
        $inexistente = '01936f1a-7c00-7a3e-9b7d-000000000000';

        $this->artisan('demo:simulate-video-failure', ['videoAttemptId' => $inexistente])
            ->assertSuccessful();

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => ProcessingEventId::para($inexistente, ProcessingScenario::FAILURE),
            'video_attempt_id' => null,
            'outcome' => 'rejected_permanent',
        ]);
    }
}
