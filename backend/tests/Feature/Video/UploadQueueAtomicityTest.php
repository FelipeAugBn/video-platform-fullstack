<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Models\User;
use App\Video\Application\Port\ProcessingQueue;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use App\Video\Infrastructure\Queue\QueuedProcessing;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CatalogoDeTeste;
use Tests\Support\Video\ObjectStorageFake;
use Tests\Support\Video\VideoDeTeste;
use Tests\TestCase;

/**
 * O enfileiramento e atomico com a mudanca de estado (plan §§7.4, 13.1).
 *
 * ## Por que este teste nao pode usar fila falsa nem driver `sync`
 *
 * `Queue::fake()` intercepta o despacho **antes** de ele chegar ao banco, e o
 * driver `sync` executa o job na hora. Nenhum dos dois chega perto de uma linha
 * na tabela `jobs`, entao nenhum dos dois prova atomicidade transacional —
 * provam contrato e efeito, que sao outra coisa e ja estao cobertos em
 * `UploadCompleteTest`.
 *
 * Aqui a conexao e `database`, sobre o **MySQL de testes**, e a afirmacao e
 * sobre linhas: depois do rollback nao existe nem a alteracao de dominio nem a
 * linha de `jobs`; depois do commit existem as duas.
 *
 * ## Por que `DatabaseTruncation` e nao `RefreshDatabase`
 *
 * `RefreshDatabase` envolve cada teste numa transacao, e as transacoes da
 * aplicacao viram pontos de salvamento dentro dela. Dava para afirmar algo assim,
 * mas seria uma afirmacao sobre pontos de salvamento — e o que este teste precisa
 * observar sao **commits de verdade**, que e o que o `worker` enxergaria.
 *
 * ## O `worker` de pe nao interfere
 *
 * Ele consome a fila do banco da **aplicacao**; a suite roda contra o schema de
 * testes, escolhido por `DB_TEST_DATABASE` antes de a aplicacao de teste ser
 * criada. Os dois nunca se encontram, e por isso nao e preciso parar processo
 * nenhum para rodar isto.
 */
final class UploadQueueAtomicityTest extends TestCase
{
    use CatalogoDeTeste;
    use DatabaseTruncation;
    use VideoDeTeste;

    private User $produtor;

    private VideoAttempt $tentativa;

    private ObjectStorageFake $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // A conexao de fila da suite e `sync` por padrao (`phpunit.xml`), e e ela
        // que os demais testes usam. Aqui a troca e o proprio objeto do teste.
        config(['queue.default' => 'database']);

        $this->produtor = User::factory()->producer()->create();
        $aula = $this->umaAula($this->umModulo($this->umCurso($this->produtor)));
        $this->storage = $this->storageFalso();
        $this->tentativa = $this->umaTentativa($aula, VideoState::UPLOADING);
        $this->objetoValidoPara($this->tentativa, $this->storage);

        DB::table('jobs')->delete();

        // A falha provocada no teste de rollback e esperada, e o registro dela
        // com pilha completa afogaria a saida da suite. O canal nulo silencia o
        // relato sem tocar no comportamento.
        config(['logging.default' => 'null']);
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

    public function test_o_commit_deixa_o_estado_e_a_linha_de_jobs_persistidos(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$this->tentativa->id()}/complete",
            ['parts' => [['part_number' => 1, 'etag' => '"etag"']]],
        );

        $resposta->assertStatus(202);

        $this->assertSame(
            VideoState::UPLOADED->value,
            DB::table('video_attempts')->where('id', $this->tentativa->id())->value('state'),
        );

        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
    }

    public function test_o_rollback_nao_deixa_nem_estado_nem_linha_de_jobs(): void
    {
        // O despacho acontece **dentro** da transacao, e uma falha depois dele
        // desfaz os dois. O dublê despacha de verdade — pela mesma conexao — e so
        // entao lanca: e a unica forma de observar a linha de `jobs` nascendo e
        // desaparecendo com o estado.
        $this->app->instance(ProcessingQueue::class, new class(new QueuedProcessing) implements ProcessingQueue
        {
            public function __construct(private readonly QueuedProcessing $real) {}

            public function scheduleProcessing(string $videoAttemptId): void
            {
                $this->real->scheduleProcessing($videoAttemptId);

                throw new RuntimeException('Falha apos o despacho, antes do commit.');
            }

            public function scheduleDelivery(string $videoAttemptId, ProcessingScenario $scenario): void
            {
                $this->real->scheduleDelivery($videoAttemptId, $scenario);
            }
        });

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/video-uploads/{$this->tentativa->id()}/complete",
            ['parts' => [['part_number' => 1, 'etag' => '"etag"']]],
        );

        $resposta->assertStatus(500);

        // Nem uma coisa nem outra: as duas pertenciam ao mesmo commit.
        $this->assertSame(
            VideoState::UPLOADING->value,
            DB::table('video_attempts')->where('id', $this->tentativa->id())->value('state'),
        );

        $this->assertSame(0, DB::table('jobs')->count());
    }
}
