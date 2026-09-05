<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Catalog\Interfaces\Http\Request\CreateLessonRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `POST /api/modules/{module}/lessons`.
 *
 * As mesmas garantias de ordem da criacao de modulo, um nivel abaixo da arvore
 * (AC-PROD-003), mais duas que so existem aqui: a aula nasce **rascunho** e
 * **sem video** (RF-AUL-004, RF-AUL-005).
 *
 * A trava aqui e a da linha do **modulo**, e nao a do curso: duas aulas criadas
 * ao mesmo tempo em modulos diferentes nao disputam sequencia nenhuma
 * (plan §8.5).
 */
final class LessonCreationTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private User $produtor;

    private Course $curso;

    private Module $modulo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor);
        $this->modulo = $this->umModulo($this->curso);
    }

    // -----------------------------------------------------------------------
    // Caminho feliz
    // -----------------------------------------------------------------------

    public function test_o_produtor_cria_uma_aula_e_recebe_201(): void
    {
        $resposta = $this->criar('Primeiros passos');

        $resposta->assertCreated();
        $resposta->assertJsonPath('data.title', 'Primeiros passos');
        $resposta->assertJsonPath('data.module_id', $this->modulo->id());
    }

    public function test_a_resposta_traz_exatamente_os_seis_campos_do_contrato(): void
    {
        $this->assertSame(
            ['id', 'module_id', 'title', 'position', 'published_at', 'video_state'],
            array_keys($this->criar()->json('data')),
        );
    }

    public function test_o_identificador_e_um_uuid_versao_7(): void
    {
        $id = $this->criar()->json('data.id');

        $this->assertTrue(Uuid::isValid($id));
        $this->assertSame(7, Uuid::fromString($id)->getFields()->getVersion());
    }

    public function test_a_aula_criada_e_gravada_no_banco(): void
    {
        $id = $this->criar('Primeiros passos')->json('data.id');

        $this->assertDatabaseHas('lessons', [
            'id' => $id,
            'module_id' => $this->modulo->id(),
            'title' => 'Primeiros passos',
            'position' => 1,
        ]);
    }

    // -----------------------------------------------------------------------
    // Rascunho e sem video
    // -----------------------------------------------------------------------

    public function test_a_aula_nasce_sem_publicacao(): void
    {
        $resposta = $this->criar();

        // `null`, e nao ausente: o cliente distingue rascunho sem testar a
        // presenca da chave (RF-AUL-004).
        $this->assertNull($resposta->json('data.published_at'));
        $this->assertArrayHasKey('published_at', $resposta->json('data'));

        $this->assertDatabaseHas('lessons', [
            'id' => $resposta->json('data.id'),
            'published_at' => null,
        ]);
    }

    public function test_a_aula_nasce_sem_video(): void
    {
        $resposta = $this->criar();

        $this->assertNull($resposta->json('data.video_state'));
        $this->assertArrayHasKey('video_state', $resposta->json('data'));

        $this->assertDatabaseHas('lessons', [
            'id' => $resposta->json('data.id'),
            'current_video_attempt_id' => null,
        ]);
        $this->assertDatabaseCount('video_attempts', 0);
    }

    public function test_publicacao_e_video_enviados_no_corpo_nao_tem_efeito(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/modules/{$this->modulo->id()}/lessons",
            [
                'title' => 'Primeiros passos',
                'published_at' => '2020-01-01T00:00:00+00:00',
                'video_state' => 'ready',
                'current_video_attempt_id' => (string) Uuid::uuid7(),
            ],
        );

        $resposta->assertCreated();
        $this->assertNull($resposta->json('data.published_at'));
        $this->assertNull($resposta->json('data.video_state'));

        $this->assertDatabaseHas('lessons', [
            'id' => $resposta->json('data.id'),
            'published_at' => null,
            'current_video_attempt_id' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // A ordem de criacao (AC-PROD-003)
    // -----------------------------------------------------------------------

    public function test_tres_aulas_ocupam_as_posicoes_um_dois_e_tres(): void
    {
        $this->assertSame(1, $this->criar('Primeira')->json('data.position'));
        $this->assertSame(2, $this->criar('Segunda')->json('data.position'));
        $this->assertSame(3, $this->criar('Terceira')->json('data.position'));
    }

    public function test_a_quarta_aula_ocupa_a_posicao_quatro(): void
    {
        foreach (['Primeira', 'Segunda', 'Terceira'] as $titulo) {
            $this->criar($titulo);
        }

        $this->assertSame(4, $this->criar('Quarta')->json('data.position'));
    }

    public function test_nenhuma_aula_existente_muda_de_posicao(): void
    {
        $anteriores = [];

        foreach (['Primeira', 'Segunda', 'Terceira'] as $titulo) {
            $dados = $this->criar($titulo)->json('data');
            $anteriores[$dados['id']] = $dados['position'];
        }

        $this->criar('Quarta');

        foreach ($anteriores as $id => $posicao) {
            $this->assertDatabaseHas('lessons', ['id' => $id, 'position' => $posicao]);
        }
    }

    public function test_cada_modulo_tem_a_propria_sequencia(): void
    {
        $outroModulo = $this->umModulo($this->curso, 'Segundo modulo');

        $this->criar('Primeira');
        $this->criar('Segunda');

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$outroModulo->id()}/lessons", ['title' => 'Primeira do segundo']);

        // A posicao e dentro do modulo, e nao dentro do curso (RN-ORD-001).
        $this->assertSame(1, $resposta->json('data.position'));
    }

    public function test_posicao_enviada_no_corpo_nao_influencia_o_resultado(): void
    {
        $primeira = $this->criar('Primeira')->json('data');

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/modules/{$this->modulo->id()}/lessons",
            ['title' => 'Segunda', 'position' => 1],
        );

        $resposta->assertCreated();
        $this->assertSame(2, $resposta->json('data.position'));
        $this->assertDatabaseHas('lessons', ['id' => $primeira['id'], 'position' => 1]);
    }

    // -----------------------------------------------------------------------
    // Isolamento entre produtores
    // -----------------------------------------------------------------------

    public function test_criar_aula_em_modulo_alheio_responde_404(): void
    {
        $moduloAlheio = $this->umModulo($this->umCurso(User::factory()->producer()->create()));

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$moduloAlheio->id()}/lessons", ['title' => 'Invasao']);

        $resposta->assertNotFound();
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'NOT_FOUND');
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_modulo_alheio_e_modulo_inexistente_respondem_identicamente(): void
    {
        $moduloAlheio = $this->umModulo($this->umCurso(User::factory()->producer()->create()));
        $inexistente = (string) Uuid::uuid7();

        $alheio = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$moduloAlheio->id()}/lessons", ['title' => 'Titulo']);
        $ausente = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$inexistente}/lessons", ['title' => 'Titulo']);

        $this->assertSame($alheio->getStatusCode(), $ausente->getStatusCode());
        $this->assertSame($alheio->getContent(), $ausente->getContent());
        $this->assertSame(
            $alheio->headers->get('content-type'),
            $ausente->headers->get('content-type'),
        );
    }

    // -----------------------------------------------------------------------
    // Entrada invalida
    // -----------------------------------------------------------------------

    public function test_titulo_ausente_responde_422_em_portugues(): void
    {
        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$this->modulo->id()}/lessons", []);

        $resposta->assertStatus(422);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(['O campo titulo e obrigatorio.'], $resposta->json('errors.title'));
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_titulo_maior_que_a_coluna_responde_422(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/modules/{$this->modulo->id()}/lessons",
            ['title' => str_repeat('a', CreateLessonRequest::LIMITE_TITULO + 1)],
        );

        $resposta->assertStatus(422);
        $this->assertSame(
            ['O campo titulo nao pode ter mais de 255 caracteres.'],
            $resposta->json('errors.title'),
        );
    }

    public function test_titulo_no_limite_exato_da_coluna_e_aceito(): void
    {
        $this->actingAs($this->produtor)->postJson(
            "/api/modules/{$this->modulo->id()}/lessons",
            ['title' => str_repeat('a', CreateLessonRequest::LIMITE_TITULO)],
        )->assertCreated();
    }

    // -----------------------------------------------------------------------
    // A trava, a transacao e o calculo da posicao
    // -----------------------------------------------------------------------

    public function test_a_criacao_trava_a_linha_do_modulo(): void
    {
        $consultas = $this->capturarConsultas(fn () => $this->criar());

        $comTrava = array_filter(
            $consultas,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertNotEmpty($comTrava, 'a criacao precisa travar o pai');

        // O pai da aula e o **modulo**, e nao o curso (plan §8.5). Travar o curso
        // serializaria criacoes em modulos diferentes sem necessidade.
        foreach ($comTrava as $sql) {
            $this->assertStringContainsString('from `modules`', strtolower($sql), $sql);
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
        }
    }

    public function test_o_calculo_da_posicao_acontece_dentro_da_transacao(): void
    {
        $niveis = [];
        $this->espionarRepositorio($niveis);

        $nivelFora = DB::transactionLevel();
        $this->criar()->assertCreated();

        $this->assertGreaterThan($nivelFora, $niveis['nextPosition']);
        $this->assertSame($niveis['nextPosition'], $niveis['save']);
    }

    public function test_falha_depois_do_calculo_nao_deixa_aula_gravada(): void
    {
        $this->app->instance(LessonRepository::class, new class($this->app->make(LessonRepository::class)) implements LessonRepository
        {
            public function __construct(private readonly LessonRepository $real) {}

            public function nextIdentity(): string
            {
                return $this->real->nextIdentity();
            }

            public function save(Lesson $lesson): void
            {
                $this->real->save($lesson);

                throw new RuntimeException('falha depois de gravar');
            }

            public function findOwned(string $lessonId, string $ownerId): ?Lesson
            {
                return $this->real->findOwned($lessonId, $ownerId);
            }

            public function nextPosition(string $moduleId): int
            {
                return $this->real->nextPosition($moduleId);
            }

            public function listOfOwnedModule(string $moduleId, string $ownerId): array
            {
                return $this->real->listOfOwnedModule($moduleId, $ownerId);
            }
        });

        $this->criar()->assertStatus(500);

        $this->assertDatabaseCount('lessons', 0);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function criar(string $titulo = 'Primeiros passos'): TestResponse
    {
        return $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$this->modulo->id()}/lessons", ['title' => $titulo]);
    }

    /**
     * @param  array<string, int>  $niveis
     */
    private function espionarRepositorio(array &$niveis): void
    {
        $real = $this->app->make(LessonRepository::class);

        $this->app->instance(LessonRepository::class, new class($real, $niveis) implements LessonRepository
        {
            /**
             * @param  array<string, int>  $niveis
             */
            public function __construct(
                private readonly LessonRepository $real,
                private array &$niveis,
            ) {}

            public function nextIdentity(): string
            {
                return $this->real->nextIdentity();
            }

            public function save(Lesson $lesson): void
            {
                $this->niveis['save'] = DB::transactionLevel();

                $this->real->save($lesson);
            }

            public function findOwned(string $lessonId, string $ownerId): ?Lesson
            {
                return $this->real->findOwned($lessonId, $ownerId);
            }

            public function nextPosition(string $moduleId): int
            {
                $this->niveis['nextPosition'] = DB::transactionLevel();

                return $this->real->nextPosition($moduleId);
            }

            public function listOfOwnedModule(string $moduleId, string $ownerId): array
            {
                return $this->real->listOfOwnedModule($moduleId, $ownerId);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function capturarConsultas(callable $acao): array
    {
        $consultas = [];

        DB::listen(function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        $acao();

        DB::flushQueryLog();

        return $consultas;
    }
}
