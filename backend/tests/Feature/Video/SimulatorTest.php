<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Domain\ProcessingEventId;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Simulator\CallbackDelivery;
use App\Video\Infrastructure\Simulator\SimulatorDeliveryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\EncaminhaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * O fornecedor simulado, chamando o callback de verdade (RF-PROC-003, 004, 006,
 * plan §13.3).
 *
 * Duas afirmacoes sustentam esta tarefa, e elas sao independentes:
 *
 * **O caminho e HTTP.** O video chega a `ready` porque um `POST` assinado
 * atravessou a validacao de origem, a validacao estrutural e o caso de uso do
 * callback. Um simulador que escrevesse na tabela produziria o mesmo estado
 * final sem exercitar nada disso.
 *
 * **O simulador nao escreve no dominio.** Isso e verificado sobre os arquivos, e
 * nao pelo efeito: um teste de efeito passaria mesmo se o componente tivesse um
 * repositorio guardado para "casos especiais".
 */
final class SimulatorTest extends TestCase
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

    // -----------------------------------------------------------------------
    // Desfecho deterministico (RF-PROC-006)
    // -----------------------------------------------------------------------

    public function test_o_fluxo_normal_leva_o_video_a_ready_pelo_caminho_http(): void
    {
        $this->encaminharCallbacksParaAAplicacao();

        $this->entregar(ProcessingScenario::SUCCESS);

        $this->assertSame([200], array_column($this->callbacksEncaminhados, 'status'));

        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
        ]);

        $this->assertNotNull(
            DB::table('video_attempts')->where('id', $this->videoId)->value('playback_reference'),
        );
    }

    public function test_a_entrega_usa_o_event_id_estavel_do_cenario(): void
    {
        $this->encaminharCallbacksParaAAplicacao();

        $this->entregar(ProcessingScenario::SUCCESS);

        $this->assertDatabaseHas('webhook_events', [
            'event_id' => ProcessingEventId::para($this->videoId, ProcessingScenario::SUCCESS),
            'video_attempt_id' => $this->videoId,
            'outcome' => 'accepted',
        ]);
    }

    public function test_a_carga_entregue_e_a_oficial_do_desafio(): void
    {
        $capturado = null;
        Http::fake(function ($requisicao) use (&$capturado) {
            $capturado = json_decode($requisicao->body(), true);

            return Http::response('{}', 200);
        });

        $this->entregar(ProcessingScenario::SUCCESS);

        // Quatro campos, e nenhum a mais: nada foi acrescentado ao contrato do
        // desafio (plan §13.4).
        $this->assertSame(
            ['event_id', 'video_id', 'status', 'playback_reference'],
            array_keys((array) $capturado),
        );
    }

    // -----------------------------------------------------------------------
    // Politica de repeticao (RF-WHK-009)
    // -----------------------------------------------------------------------

    public function test_resposta_5xx_faz_a_entrega_ser_repetida(): void
    {
        Http::fake(fn () => Http::response('{}', 503));

        // Lancar e o que faz a fila reentregar: o simulador nao decide sozinho
        // que desistiu.
        $this->expectException(RuntimeException::class);

        $this->entregar(ProcessingScenario::SUCCESS);
    }

    public function test_falha_de_rede_faz_a_entrega_ser_repetida(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sem rota'));

        $this->expectException(RuntimeException::class);

        $this->entregar(ProcessingScenario::SUCCESS);
    }

    public function test_resposta_200_encerra_a_entrega(): void
    {
        Http::fake(fn () => Http::response('{}', 200));

        $this->entregar(ProcessingScenario::SUCCESS);

        // Sem excecao: o desfecho e definitivo e o emissor para.
        $this->assertTrue(true);
    }

    public function test_resposta_409_encerra_a_entrega(): void
    {
        // `409` instrui a parar tanto quanto `200`: o evento nunca sera aceito, e
        // insistir seria ruido.
        Http::fake(fn () => Http::response('{}', 409));

        $this->entregar(ProcessingScenario::SUCCESS);

        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Restricao arquitetural (plan §13.3)
    // -----------------------------------------------------------------------

    public function test_o_simulador_nao_referencia_o_dominio_nem_a_persistencia(): void
    {
        // Verificado sobre os arquivos, e nao pelo efeito: um teste de efeito
        // passaria mesmo se o componente guardasse um repositorio para "casos
        // especiais". A unica forma de o simulador afetar o estado precisa
        // continuar sendo o `POST` assinado.
        $proibidos = ['Repository', 'Eloquent', 'Illuminate\\Support\\Facades\\DB', 'VideoAttempt', 'Model'];

        foreach (glob(dirname(__DIR__, 3).'/app/Video/Infrastructure/Simulator/*.php') ?: [] as $arquivo) {
            $codigo = self::semComentarios($arquivo);

            foreach ($proibidos as $termo) {
                $this->assertStringNotContainsString(
                    $termo,
                    $codigo,
                    basename($arquivo).' referencia '.$termo,
                );
            }
        }
    }

    public function test_existem_arquivos_de_simulador_a_verificar(): void
    {
        // O par positivo do teste acima: sem ele, um caminho errado faria a
        // busca devolver lista vazia e a verificacao passaria sem verificar nada.
        $this->assertNotEmpty(glob(dirname(__DIR__, 3).'/app/Video/Infrastructure/Simulator/*.php'));
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function entregar(ProcessingScenario $cenario): void
    {
        // Pelo `handle` do job da fila `simulator`, que e o caminho que o
        // `simulator-worker` executa.
        (new SimulatorDeliveryJob($this->videoId, $cenario))
            ->handle($this->app->make(CallbackDelivery::class));
    }

    private static function semComentarios(string $caminho): string
    {
        $codigo = '';

        foreach (token_get_all((string) file_get_contents($caminho)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $codigo .= $token[1];

                continue;
            }

            $codigo .= $token;
        }

        return $codigo;
    }
}
