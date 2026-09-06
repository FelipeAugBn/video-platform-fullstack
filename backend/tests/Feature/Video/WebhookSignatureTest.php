<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\AssinaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * Validacao de origem do callback (RF-WHK-001, RF-ERR-009, plan §13.4).
 *
 * Toda recusa e `401` **sem efeito**: o video nao muda, e nenhuma reserva de
 * idempotencia e gasta. Se uma entrega nao autenticada consumisse `event_id`,
 * bastaria adivinhar identificadores para envenenar eventos legitimos que ainda
 * nem chegaram.
 *
 * As recusas nao se distinguem entre si de proposito: dizer se o problema foi a
 * assinatura ou o horario ensinaria a quem tenta.
 */
final class WebhookSignatureTest extends TestCase
{
    use AssinaCallback;
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();

        $aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
        $this->videoId = $this->umaTentativa($aula, VideoState::PROCESSING)->id();
    }

    public function test_a_rota_nao_exige_sessao_nem_token_csrf(): void
    {
        // Sem `actingAs` e sem cookie CSRF: o emissor e um servico, e o que prova
        // a origem e a assinatura.
        $this->entregar($this->cargaDeSucesso($this->videoId, 'evento-legitimo'))->assertOk();
    }

    public function test_assinatura_ausente_responde_401_sem_efeito(): void
    {
        $resposta = $this->entregar($this->cargaDeSucesso($this->videoId, 'evento-1'), assinatura: '');

        $resposta->assertStatus(401);
        $resposta->assertJsonPath('code', 'WEBHOOK_SIGNATURE_INVALID');

        $this->assertSemEfeito();
    }

    public function test_assinatura_invalida_responde_401_sem_efeito(): void
    {
        $resposta = $this->entregar(
            $this->cargaDeSucesso($this->videoId, 'evento-2'),
            assinatura: 'v1='.str_repeat('a', 64),
        );

        $resposta->assertStatus(401);
        $this->assertSemEfeito();
    }

    public function test_assinatura_sem_o_prefixo_de_versao_responde_401(): void
    {
        $corpo = (string) json_encode($this->cargaDeSucesso($this->videoId, 'evento-3'));
        $momento = (string) time();

        // O HMAC correto, mas sem `v1=`: o formato faz parte do contrato.
        $this->entregarBruto(
            $corpo,
            $momento,
            hash_hmac('sha256', $momento.'.'.$corpo, (string) config('video.webhook.secret')),
        )->assertStatus(401);

        $this->assertSemEfeito();
    }

    public function test_corpo_alterado_apos_assinar_responde_401(): void
    {
        $original = (string) json_encode($this->cargaDeSucesso($this->videoId, 'evento-4'));
        $momento = (string) time();
        $assinatura = $this->assinar($momento, $original);

        $adulterado = (string) json_encode($this->cargaDeSucesso($this->videoId, 'evento-4', 'videos/outro.mp4'));

        // A verificacao usa o corpo bruto, byte a byte: trocar um campo depois de
        // assinar invalida a entrega.
        $this->entregarBruto($adulterado, $momento, $assinatura)->assertStatus(401);

        $this->assertSemEfeito();
    }

    public function test_timestamp_fora_da_janela_responde_401(): void
    {
        $antigo = time() - (int) config('video.webhook.tolerance') - 60;

        $this->entregar($this->cargaDeSucesso($this->videoId, 'evento-5'), timestamp: $antigo)
            ->assertStatus(401);

        $this->assertSemEfeito();
    }

    public function test_timestamp_no_futuro_alem_da_janela_responde_401(): void
    {
        // A janela vale nos dois sentidos: sem isso, bastaria assinar com uma
        // data distante para produzir uma entrega que vale para sempre.
        $futuro = time() + (int) config('video.webhook.tolerance') + 60;

        $this->entregar($this->cargaDeSucesso($this->videoId, 'evento-6'), timestamp: $futuro)
            ->assertStatus(401);

        $this->assertSemEfeito();
    }

    public function test_timestamp_nao_numerico_responde_401(): void
    {
        $corpo = (string) json_encode($this->cargaDeSucesso($this->videoId, 'evento-7'));

        $this->entregarBruto($corpo, 'ontem', $this->assinar('ontem', $corpo))->assertStatus(401);

        $this->assertSemEfeito();
    }

    public function test_segredo_vazio_recusa_toda_entrega(): void
    {
        // Sem segredo, calcular HMAC sobre chave vazia aceitaria qualquer emissor
        // que soubesse do descuido. A resposta e a generica de erro interno:
        // descrever a causa diria a quem tenta que basta esperar.
        config(['video.webhook.secret' => '']);
        $this->refreshApplication();
        config(['video.webhook.secret' => '']);

        $resposta = $this->entregar($this->cargaDeSucesso($this->videoId, 'evento-8'));

        $this->assertContains($resposta->status(), [401, 500]);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    private function assertSemEfeito(): void
    {
        // Nem desfecho registrado, nem transicao aplicada.
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::PROCESSING->value,
        ]);
    }
}
