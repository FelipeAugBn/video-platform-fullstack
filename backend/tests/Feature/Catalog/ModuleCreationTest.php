<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Module;
use App\Catalog\Interfaces\Http\Request\CreateModuleRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `POST /api/courses/{course}/modules`.
 *
 * O centro desta tarefa esta aqui: a posicao e atribuida pelo backend, na ordem
 * de criacao, e nenhum modulo ja criado muda de lugar (AC-PROD-003, RN-ORD-002,
 * RN-ORD-003).
 *
 * A autenticacao entra por `actingAs`: a prova completa de sessao e cookies
 * pertence a T030 e ja existe. O que estes testes garantem e outra coisa — que a
 * rota esta sob os middlewares certos e que o dono vem de quem esta autenticado.
 */
final class ModuleCreationTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private User $produtor;

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor);
    }

    // -----------------------------------------------------------------------
    // Caminho feliz
    // -----------------------------------------------------------------------

    public function test_o_produtor_cria_um_modulo_e_recebe_201(): void
    {
        $resposta = $this->criar('Introducao');

        $resposta->assertCreated();
        $resposta->assertJsonPath('data.title', 'Introducao');
        $resposta->assertJsonPath('data.course_id', $this->curso->id());
    }

    public function test_a_resposta_traz_exatamente_os_quatro_campos_do_contrato(): void
    {
        // A lista fechada, e nao apenas a presenca: um campo a mais tambem quebra
        // o contrato, porque passa a ser algo que o cliente recebe e ninguem
        // prometeu manter.
        $this->assertSame(
            ['id', 'course_id', 'title', 'position'],
            array_keys($this->criar()->json('data')),
        );
    }

    public function test_o_identificador_e_um_uuid_versao_7(): void
    {
        $id = $this->criar()->json('data.id');

        $this->assertTrue(Uuid::isValid($id));
        $this->assertSame(7, Uuid::fromString($id)->getFields()->getVersion());
    }

    public function test_o_modulo_criado_e_gravado_no_banco(): void
    {
        $id = $this->criar('Introducao')->json('data.id');

        $this->assertDatabaseHas('modules', [
            'id' => $id,
            'course_id' => $this->curso->id(),
            'title' => 'Introducao',
            'position' => 1,
        ]);
    }

    // -----------------------------------------------------------------------
    // A ordem de criacao (AC-PROD-003)
    // -----------------------------------------------------------------------

    public function test_tres_modulos_ocupam_as_posicoes_um_dois_e_tres(): void
    {
        $this->assertSame(1, $this->criar('Primeiro')->json('data.position'));
        $this->assertSame(2, $this->criar('Segundo')->json('data.position'));
        $this->assertSame(3, $this->criar('Terceiro')->json('data.position'));
    }

    public function test_o_quarto_modulo_ocupa_a_posicao_quatro(): void
    {
        foreach (['Primeiro', 'Segundo', 'Terceiro'] as $titulo) {
            $this->criar($titulo);
        }

        $this->assertSame(4, $this->criar('Quarto')->json('data.position'));
    }

    public function test_nenhum_modulo_existente_muda_de_posicao(): void
    {
        $anteriores = [];

        foreach (['Primeiro', 'Segundo', 'Terceiro'] as $titulo) {
            $dados = $this->criar($titulo)->json('data');
            $anteriores[$dados['id']] = $dados['position'];
        }

        $this->criar('Quarto');

        // A posicao nova e sempre a proxima livre, nunca uma insercao no meio:
        // nao ha reescrita em massa das posicoes seguintes (RN-ORD-003).
        foreach ($anteriores as $id => $posicao) {
            $this->assertDatabaseHas('modules', ['id' => $id, 'position' => $posicao]);
        }
    }

    public function test_nao_ha_posicoes_duplicadas_nem_buracos(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->criar("Modulo {$i}");
        }

        $posicoes = DB::table('modules')
            ->where('course_id', $this->curso->id())
            ->orderBy('position')
            ->pluck('position')
            ->all();

        $this->assertSame([1, 2, 3, 4, 5, 6], array_map('intval', $posicoes));
        $this->assertSame(6, count(array_unique($posicoes)));
    }

    public function test_cada_curso_tem_a_propria_sequencia(): void
    {
        $outroCurso = $this->umCurso($this->produtor, 'Outro curso do mesmo produtor');

        $this->criar('Primeiro');
        $this->criar('Segundo');

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$outroCurso->id()}/modules", ['title' => 'Primeiro de outro curso']);

        // A sequencia e por curso: o segundo curso comeca em 1 de novo
        // (RN-ORD-002).
        $this->assertSame(1, $resposta->json('data.position'));
    }

    // -----------------------------------------------------------------------
    // A posicao nao vem do cliente (RF-MOD-007)
    // -----------------------------------------------------------------------

    public function test_posicao_enviada_no_corpo_nao_influencia_o_resultado(): void
    {
        $this->criar('Primeiro');

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => 'Segundo', 'position' => 99],
        );

        $resposta->assertCreated();
        $this->assertSame(2, $resposta->json('data.position'));
        $this->assertDatabaseMissing('modules', ['position' => 99]);
    }

    public function test_posicao_um_enviada_no_corpo_nao_desloca_o_primeiro_modulo(): void
    {
        // O caso que mais parece funcionar: pedir a posicao 1 quando ela ja esta
        // ocupada. Se o corpo tivesse efeito, ou a UNIQUE recusaria a criacao, ou
        // o primeiro modulo seria empurrado — e nenhuma das duas coisas pode
        // acontecer.
        $primeiro = $this->criar('Primeiro')->json('data');

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => 'Segundo', 'position' => 1],
        );

        $resposta->assertCreated();
        $this->assertSame(2, $resposta->json('data.position'));
        $this->assertDatabaseHas('modules', ['id' => $primeiro['id'], 'position' => 1]);
    }

    public function test_id_e_course_id_enviados_no_corpo_nao_sobrescrevem_o_backend(): void
    {
        $idForjado = (string) Uuid::uuid7();
        $outroCurso = $this->umCurso($this->produtor, 'Outro curso');

        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => 'Introducao', 'id' => $idForjado, 'course_id' => $outroCurso->id()],
        );

        $resposta->assertCreated();
        $this->assertNotSame($idForjado, $resposta->json('data.id'));
        // O curso e o da rota, e nao o do corpo.
        $this->assertSame($this->curso->id(), $resposta->json('data.course_id'));
        $this->assertDatabaseMissing('modules', ['id' => $idForjado]);
    }

    // -----------------------------------------------------------------------
    // Isolamento entre produtores
    // -----------------------------------------------------------------------

    public function test_criar_modulo_em_curso_alheio_responde_404(): void
    {
        $outro = User::factory()->producer()->create();
        $cursoAlheio = $this->umCurso($outro);

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$cursoAlheio->id()}/modules", ['title' => 'Invasao']);

        $resposta->assertNotFound();
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'NOT_FOUND');

        // E nada foi criado no curso do outro (RF-MOD-004).
        $this->assertDatabaseCount('modules', 0);
    }

    public function test_curso_alheio_e_curso_inexistente_respondem_identicamente(): void
    {
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());
        $inexistente = (string) Uuid::uuid7();

        $alheio = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$cursoAlheio->id()}/modules", ['title' => 'Titulo']);
        $ausente = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$inexistente}/modules", ['title' => 'Titulo']);

        // Byte a byte: um `403` no primeiro caso, ou um corpo diferente,
        // confirmaria que aquele identificador existe (RN-PROP-005).
        $this->assertSame($alheio->getStatusCode(), $ausente->getStatusCode());
        $this->assertSame($alheio->getContent(), $ausente->getContent());
        $this->assertSame(
            $alheio->headers->get('content-type'),
            $ausente->headers->get('content-type'),
        );
    }

    public function test_identificador_de_curso_malformado_responde_404(): void
    {
        // A restricao de formato na rota recusa antes de qualquer codigo da
        // aplicacao rodar, e o resultado e o mesmo `404` generico: um
        // identificador invalido nao merece resposta diferente de um que apenas
        // nao existe.
        $this->actingAs($this->produtor)
            ->postJson('/api/courses/nao-e-uuid/modules', ['title' => 'Titulo'])
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Entrada invalida
    // -----------------------------------------------------------------------

    public function test_titulo_ausente_responde_422_em_portugues(): void
    {
        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$this->curso->id()}/modules", []);

        $resposta->assertStatus(422);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->assertSame(['O campo titulo e obrigatorio.'], $resposta->json('errors.title'));
        $this->assertDatabaseCount('modules', 0);
    }

    public function test_titulo_que_nao_e_texto_responde_422(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => ['nao', 'e', 'texto']],
        );

        $resposta->assertStatus(422);
        $this->assertSame(['O campo titulo precisa ser um texto.'], $resposta->json('errors.title'));
    }

    public function test_titulo_maior_que_a_coluna_responde_422(): void
    {
        $resposta = $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => str_repeat('a', CreateModuleRequest::LIMITE_TITULO + 1)],
        );

        $resposta->assertStatus(422);
        $this->assertSame(
            ['O campo titulo nao pode ter mais de 255 caracteres.'],
            $resposta->json('errors.title'),
        );
    }

    public function test_titulo_no_limite_exato_da_coluna_e_aceito(): void
    {
        // O par positivo: sem ele, uma regra que recusasse tudo passaria igual.
        $this->actingAs($this->produtor)->postJson(
            "/api/courses/{$this->curso->id()}/modules",
            ['title' => str_repeat('a', CreateModuleRequest::LIMITE_TITULO)],
        )->assertCreated();
    }

    public function test_a_validacao_acontece_antes_de_o_curso_ser_procurado(): void
    {
        // Um titulo invalido em um curso alheio responde `422`, e nao `404`. A
        // ordem importa: `404` primeiro transformaria a validacao num detector de
        // existencia — bastaria enviar corpo invalido para saber se o curso e de
        // alguem.
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());

        $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$cursoAlheio->id()}/modules", [])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // A trava, a transacao e o calculo da posicao
    // -----------------------------------------------------------------------

    public function test_a_criacao_trava_a_linha_do_curso(): void
    {
        $consultas = $this->capturarConsultas(fn () => $this->criar());

        $comTrava = array_filter(
            $consultas,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertNotEmpty($comTrava, 'a criacao precisa travar o pai');

        // A trava e sobre `courses` — o pai do modulo (plan §8.5). Sem ela, duas
        // requisicoes simultaneas leriam o mesmo `MAX(position)` e disputariam o
        // mesmo valor.
        foreach ($comTrava as $sql) {
            $this->assertStringContainsString('from `courses`', strtolower($sql), $sql);
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
        }
    }

    public function test_o_calculo_da_posicao_acontece_dentro_da_transacao(): void
    {
        // A prova e o nivel de transacao no instante da leitura, capturado por um
        // decorador da porta. Um `MAX(position)` lido fora da transacao seria
        // apenas uma leitura otimista: o numero poderia ja estar consumido quando
        // a insercao acontecesse.
        $niveis = [];
        $this->espionarRepositorio($niveis);

        $nivelFora = DB::transactionLevel();
        $this->criar()->assertCreated();

        $this->assertArrayHasKey('nextPosition', $niveis);
        $this->assertArrayHasKey('save', $niveis);
        $this->assertGreaterThan($nivelFora, $niveis['nextPosition']);
        $this->assertGreaterThan($nivelFora, $niveis['save']);
    }

    public function test_a_trava_e_o_calculo_e_a_insercao_ficam_na_mesma_transacao(): void
    {
        $niveis = [];
        $this->espionarRepositorio($niveis);

        $this->criar()->assertCreated();

        // O mesmo nivel nas duas etapas significa a mesma transacao: nao ha
        // commit entre calcular a posicao e gravar o modulo (plan §7.4).
        $this->assertSame($niveis['nextPosition'], $niveis['save']);
    }

    public function test_falha_depois_do_calculo_nao_deixa_modulo_gravado(): void
    {
        // O rollback provado pelo caminho real: a gravacao falha depois de a
        // trava ter sido obtida e a posicao calculada. Se a transacao nao
        // envolvesse as tres etapas, sobraria uma linha parcial.
        $this->app->instance(ModuleRepository::class, new class($this->app->make(ModuleRepository::class)) implements ModuleRepository
        {
            public function __construct(private readonly ModuleRepository $real) {}

            public function nextIdentity(): string
            {
                return $this->real->nextIdentity();
            }

            public function save(Module $module): void
            {
                $this->real->save($module);

                throw new RuntimeException('falha depois de gravar');
            }

            public function lockOwned(string $moduleId, string $ownerId): ?Module
            {
                return $this->real->lockOwned($moduleId, $ownerId);
            }

            public function nextPosition(string $courseId): int
            {
                return $this->real->nextPosition($courseId);
            }

            public function listOfOwnedCourse(string $courseId, string $ownerId): array
            {
                return $this->real->listOfOwnedCourse($courseId, $ownerId);
            }
        });

        $this->criar()->assertStatus(500);

        $this->assertDatabaseCount('modules', 0);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function criar(string $titulo = 'Introducao'): TestResponse
    {
        return $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$this->curso->id()}/modules", ['title' => $titulo]);
    }

    /**
     * Substitui a porta por um decorador que registra o nivel de transacao em
     * cada etapa, delegando tudo ao adapter real.
     *
     * @param  array<string, int>  $niveis
     */
    private function espionarRepositorio(array &$niveis): void
    {
        $real = $this->app->make(ModuleRepository::class);

        $this->app->instance(ModuleRepository::class, new class($real, $niveis) implements ModuleRepository
        {
            /**
             * @param  array<string, int>  $niveis
             */
            public function __construct(
                private readonly ModuleRepository $real,
                private array &$niveis,
            ) {}

            public function nextIdentity(): string
            {
                return $this->real->nextIdentity();
            }

            public function save(Module $module): void
            {
                $this->niveis['save'] = DB::transactionLevel();

                $this->real->save($module);
            }

            public function lockOwned(string $moduleId, string $ownerId): ?Module
            {
                $this->niveis['lockOwned'] = DB::transactionLevel();

                return $this->real->lockOwned($moduleId, $ownerId);
            }

            public function nextPosition(string $courseId): int
            {
                $this->niveis['nextPosition'] = DB::transactionLevel();

                return $this->real->nextPosition($courseId);
            }

            public function listOfOwnedCourse(string $courseId, string $ownerId): array
            {
                return $this->real->listOfOwnedCourse($courseId, $ownerId);
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
