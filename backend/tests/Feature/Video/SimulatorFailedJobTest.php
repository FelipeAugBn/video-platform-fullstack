<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Simulator\SimulatorDeliveryJob;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * A entrega esgotada termina em `failed_jobs`, e o video nao se mexe
 * (plan §§13.1, 13.3).
 *
 * ## Por que este teste executa o consumidor de verdade
 *
 * Chamar o componente de entrega tres vezes seguidas prova que ele lanca, e mais
 * nada. Quem conta tentativas, decide que se esgotaram e grava a linha em
 * `failed_jobs` e o **worker** — nenhuma dessas tres coisas passa pelo
 * componente. Um teste que afirmasse `failed_jobs` sem consultar `failed_jobs`
 * estaria afirmando o que nao viu.
 *
 * Aqui o caminho e o real: a conexao e a `database`, o job e despachado na fila
 * `simulator` do MySQL de testes, e `queue:work --once` roda o consumidor de
 * verdade sobre ela. As tentativas sao reduzidas a duas e a espera a zero — o
 * que muda e a rapidez da prova, nao o mecanismo.
 *
 * ## Por que `DatabaseTruncation`
 *
 * O consumidor le a tabela por conta propria. Sob `RefreshDatabase` o job ficaria
 * dentro da transacao aberta do teste e o `queue:work` nao o enxergaria — a fila
 * pareceria vazia e o teste passaria sem executar nada.
 *
 * ## O worker de pe nao interfere
 *
 * O `simulator-worker` do Compose consome a fila do banco da **aplicacao**; a
 * suite roda contra o schema de testes, escolhido por `DB_TEST_DATABASE`. Os dois
 * nunca se encontram, e nao e preciso parar processo nenhum.
 */
final class SimulatorFailedJobTest extends TestCase
{
    use CatalogoDeTeste;
    use DatabaseTruncation;
    use VideoDeTeste;

    private const TENTATIVAS = 2;

    private string $videoId;

    protected function setUp(): void
    {
        parent::setUp();

        // A conexao de fila da suite e `sync` por padrao (`phpunit.xml`). Aqui a
        // troca e o proprio objeto do teste: o mecanismo de falha que se quer
        // exercitar so existe no driver `database`.
        config(['queue.default' => 'database', 'logging.default' => 'null']);

        $aula = $this->umaAula($this->umModulo($this->umCurso(User::factory()->producer()->create())));
        $this->videoId = $this->umaTentativa($aula, VideoState::PROCESSING)->id();

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
    }

    protected function tearDown(): void
    {
        // A truncagem acontece **antes** de cada teste desta classe, entao a
        // ultima execucao deixaria as linhas commitadas para tras. As classes
        // seguintes rodam sob transacao e encontrariam um banco que nao esta
        // vazio — e uma afirmacao de contagem absoluta passaria a depender da
        // ordem em que a suite e executada. Limpar na saida fecha isso.
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_a_entrega_esgotada_vai_para_failed_jobs_e_preserva_o_video(): void
    {
        // Falha deterministica: toda tentativa recebe `500`, que e a resposta que
        // instrui o emissor a repetir. Nenhuma delas produz desfecho.
        Http::fake(fn () => Http::response('{}', 500));

        SimulatorDeliveryJob::dispatch($this->videoId, ProcessingScenario::SUCCESS)->onQueue('simulator');

        $this->assertSame(1, DB::table('jobs')->where('queue', 'simulator')->count());

        $this->consumirAteEsgotar();

        // 0. A entrega foi de fato tentada, e tentada **de novo**: sem isto, um
        //    job que falhasse por outro motivo — uma dependencia que o container
        //    nao resolvesse, por exemplo — tambem cairia em `failed_jobs`, e a
        //    prova valeria para a coisa errada.
        Http::assertSentCount(self::TENTATIVAS);

        // 1. A linha existe, e e consultada — nao inferida.
        $this->assertSame(1, DB::table('failed_jobs')->count());
        // A carga e lida e desserializada, e nao comparada como texto: o JSON
        // guardado escapa as barras invertidas do nome da classe, e uma
        // comparacao de string passaria a depender desse detalhe de formato.
        $carga = json_decode((string) DB::table('failed_jobs')->value('payload'), true);

        $this->assertSame(SimulatorDeliveryJob::class, $carga['displayName']);

        // 2. O job saiu da fila: nao ha reentrega pendente.
        $this->assertSame(0, DB::table('jobs')->count());

        // 3. **O video permanece em `processing`.** Falha tecnica de job e
        //    callback de falha nao sao a mesma coisa: a aplicacao nao recebeu
        //    desfecho nenhum, e transformar uma queda de rede em video defeituoso
        //    e exatamente o erro que a separacao entre as duas existe para evitar
        //    (plan §13.1).
        $linha = DB::table('video_attempts')->where('id', $this->videoId)->first();

        $this->assertSame(VideoState::PROCESSING->value, $linha->state);
        $this->assertNull($linha->failure_code);
        $this->assertNull($linha->failure_message);

        // 4. Nenhum desfecho definitivo registrado: o `event_id` continua
        //    elegivel, e um `queue:retry` posterior seria avaliado normalmente.
        $this->assertSame(0, DB::table('webhook_events')->count());
    }

    /**
     * Roda o consumidor real ate a fila esvaziar.
     *
     * O laco faz o papel do processo permanente: cada `--once` reserva um job,
     * executa, e o worker decide entre devolver a fila ou dar a tentativa por
     * esgotada. A margem e uma execucao a mais que o numero de tentativas, para
     * que uma fila que nao esvazie apareca como falha em vez de laco infinito.
     */
    private function consumirAteEsgotar(): void
    {
        for ($execucao = 1; $execucao <= self::TENTATIVAS + 1; $execucao++) {
            if (DB::table('jobs')->count() === 0) {
                return;
            }

            $this->artisan('queue:work', [
                '--queue' => 'simulator',
                '--once' => true,
                '--tries' => self::TENTATIVAS,
                // Sem espera entre as tentativas: o backoff de producao e de 10,
                // 30 e 60 segundos, e respeita-lo aqui so tornaria a prova lenta.
                '--backoff' => 0,
            ])->run();
        }

        $this->assertSame(0, DB::table('jobs')->count(), 'A fila nao esvaziou apos as tentativas.');
    }
}
