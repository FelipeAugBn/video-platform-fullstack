<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Domain\VideoState;
use App\Video\Domain\WebhookOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\AssinaCallback;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * A prova direcionada de concorrencia do mesmo `event_id` (plan §8.4, RNF-003).
 *
 * E o risco central do desafio — "duas entregas simultaneas do mesmo evento" —
 * e por isso ele nao e provado por inferencia. Mas provar concorrencia real num
 * teste de uma linha de execucao exige um arranjo, e vale explicar qual e.
 *
 * ## O arranjo
 *
 * Uma **segunda conexao** MySQL abre uma transacao e insere a reserva do evento
 * **sem commitar**. Ela representa a primeira entrega, ainda em voo. A entrega
 * seguinte chega pelo endpoint de verdade, na conexao da aplicacao, e tenta
 * inserir o mesmo `event_id`.
 *
 * O que acontece la e o conteudo da garantia: o InnoDB **bloqueia** o segundo
 * `INSERT` na chave unica ate a transacao concorrente terminar. Ele nao corre, e
 * nao passa junto. Com o tempo de espera de trava reduzido a um segundo, a
 * espera vira um erro observavel — e o desfecho e o previsto para uma falha
 * transitoria: `503`, rollback, nada registrado.
 *
 * ## Por que isso prova o que interessa
 *
 * A afirmacao de RN-IDM-002 nao e "duas entregas nunca chegam juntas" — e "a
 * segunda nunca produz um segundo efeito". Este teste mostra os dois lados
 * disso: sob concorrencia, a segunda entrega **espera** em vez de correr; e
 * depois que a primeira termina, ela le o desfecho ja registrado em vez de
 * aplicar de novo.
 *
 * A segunda conexao so consegue enxergar a tabela porque `webhook_events` nao
 * tem chave estrangeira para `video_attempts` — a mesma decisao que permite
 * registrar um evento sobre tentativa inexistente. Aqui ela tem um efeito
 * colateral util: a reserva pode ser criada sem depender de dados que a
 * transacao do teste ainda nao commitou.
 */
final class WebhookConcurrencyTest extends TestCase
{
    use AssinaCallback;
    use CatalogoDeTeste;
    use RefreshDatabase;
    use VideoDeTeste;

    private const EVENTO = 'evt-concorrente';

    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['logging.default' => 'null']);

        $aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
        $this->videoId = $this->umaTentativa($aula, VideoState::PROCESSING)->id();

        // Uma segunda conexao para o mesmo schema de testes, para simular a
        // transacao concorrente. A configuracao e copiada da conexao em uso, e
        // nao escrita a mao: o nome do banco vem de `DB_TEST_DATABASE` e nunca
        // esta fixado no codigo.
        config(['database.connections.concorrente' => config('database.connections.'.config('database.default'))]);
    }

    public function test_a_entrega_concorrente_espera_e_nao_cria_uma_segunda_reserva(): void
    {
        $outra = DB::connection('concorrente');
        $outra->beginTransaction();

        // A "primeira entrega": reserva criada e ainda nao confirmada.
        $outra->table('webhook_events')->insert([
            'id' => (string) Str::uuid7(),
            'event_id' => self::EVENTO,
            'received_video_id' => $this->videoId,
            'video_attempt_id' => null,
            'outcome' => null,
            'received_status' => 'ready',
            'processed_at' => now(),
        ]);

        try {
            // Um segundo de espera em vez do padrao: sem isto o teste ficaria
            // parado ate o limite do servidor. O que se observa e o bloqueio, e
            // nao quanto ele dura.
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            $resposta = $this->entregar($this->cargaDeSucesso($this->videoId, self::EVENTO));

            // A segunda entrega esperou, nao correu — e desistiu como falha
            // transitoria, que e o desfecho que instrui o emissor a repetir.
            $resposta->assertStatus(503);
            $resposta->assertHeader('Retry-After');

            // Nenhum efeito: o video nao mudou, e nao ha um segundo desfecho.
            $this->assertDatabaseHas('video_attempts', [
                'id' => $this->videoId,
                'state' => VideoState::PROCESSING->value,
            ]);
        } finally {
            $outra->rollBack();
            $outra->disconnect();
        }
    }

    public function test_depois_que_a_concorrente_conclui_a_reentrega_repete_o_desfecho(): void
    {
        // A primeira entrega, agora inteira e pelo endpoint real.
        $this->entregar($this->cargaDeSucesso($this->videoId, self::EVENTO, 'videos/primeira.mp4'))->assertOk();

        // A segunda chega depois e encontra a colisao na chave unica: ela le o
        // desfecho registrado, sem olhar o corpo recebido.
        $this->entregar($this->cargaDeSucesso($this->videoId, self::EVENTO, 'videos/segunda.mp4'))->assertOk();

        // Um unico efeito, e ele e o da primeira.
        $this->assertSame(1, DB::table('webhook_events')->where('event_id', self::EVENTO)->count());
        $this->assertDatabaseHas('video_attempts', [
            'id' => $this->videoId,
            'state' => VideoState::READY->value,
            'playback_reference' => 'videos/primeira.mp4',
        ]);
        $this->assertDatabaseHas('webhook_events', [
            'event_id' => self::EVENTO,
            'outcome' => WebhookOutcome::ACCEPTED->value,
        ]);
    }
}
