<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\CourseState;
use App\Catalog\Infrastructure\Persistence\Eloquent\CourseModel;
use App\Models\User;
use App\Shared\Application\Page;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Tests\TestCase;

/**
 * O adapter de cursos contra o MySQL de verdade.
 *
 * Contra o banco real, e nao contra um dublê, porque o que precisa ser provado
 * aqui e justamente o que um dublê nao teria: que o recorte por dono acontece na
 * consulta, que a contagem da paginacao respeita esse recorte, e que a leitura
 * travada emite `FOR UPDATE`. Um repositorio em memoria passaria em todos esses
 * testes sem que nada disso fosse verdade em producao (plan §17.2).
 */
final class CourseRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private CourseRepository $repositorio;

    private User $dono;

    private User $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositorio = $this->app->make(CourseRepository::class);
        $this->dono = User::factory()->producer()->create();
        $this->outro = User::factory()->producer()->create();
    }

    // -----------------------------------------------------------------------
    // Ida e volta
    // -----------------------------------------------------------------------

    public function test_salvar_e_reconstituir_devolve_o_mesmo_curso(): void
    {
        $criadoEm = new DateTimeImmutable('2026-09-05T13:45:12+00:00');
        $curso = Course::create(
            id: $this->repositorio->nextIdentity(),
            ownerId: (string) $this->dono->getKey(),
            title: 'Fundamentos de PHP',
            description: 'Uma introducao pratica a linguagem.',
            createdAt: $criadoEm,
        );

        $this->repositorio->save($curso);

        $relido = $this->repositorio->findOwned($curso->id(), (string) $this->dono->getKey());

        $this->assertInstanceOf(Course::class, $relido);
        $this->assertSame($curso->id(), $relido->id());
        $this->assertSame((string) $this->dono->getKey(), $relido->ownerId());
        $this->assertSame('Fundamentos de PHP', $relido->title());
        $this->assertSame('Uma introducao pratica a linguagem.', $relido->description());
        $this->assertSame(CourseState::DRAFT, $relido->state());

        // A data sobrevive a ida e volta pelo banco com o mesmo instante. Sem o
        // valor explicito na escrita, o carimbo automatico do framework teria
        // gravado o momento da insercao no lugar do instante da operacao.
        $this->assertSame($criadoEm->format(DATE_ATOM), $relido->createdAt()->format(DATE_ATOM));
    }

    public function test_o_identificador_gerado_e_um_uuid_versao_7(): void
    {
        $identificador = $this->repositorio->nextIdentity();

        $this->assertTrue(Uuid::isValid($identificador));
        $this->assertSame(7, Uuid::fromString($identificador)->getFields()->getVersion());
    }

    public function test_cada_chamada_gera_um_identificador_distinto_e_valido(): void
    {
        // O que de fato precisa valer: identificadores nao se repetem e todos sao
        // UUIDv7. A ordem entre dois gerados no mesmo instante **nao** e
        // verificada, porque a especificacao nao a garante e o projeto nao
        // depende dela (plan §7.1).
        $gerados = [];

        for ($i = 0; $i < 50; $i++) {
            $identificador = $this->repositorio->nextIdentity();

            $this->assertTrue(Uuid::isValid($identificador));
            $this->assertSame(7, Uuid::fromString($identificador)->getFields()->getVersion());

            $gerados[] = $identificador;
        }

        $this->assertCount(50, array_unique($gerados));
    }

    // -----------------------------------------------------------------------
    // O dono e parte da consulta
    // -----------------------------------------------------------------------

    public function test_a_busca_encontra_o_curso_do_proprio_dono(): void
    {
        $curso = $this->cursoDe($this->dono);

        $this->assertNotNull($this->repositorio->findOwned($curso->id(), (string) $this->dono->getKey()));
    }

    public function test_a_busca_nao_encontra_curso_de_outro_produtor(): void
    {
        $curso = $this->cursoDe($this->outro);

        // O curso existe na tabela; ele so nao existe para este dono.
        $this->assertDatabaseHas('courses', ['id' => $curso->id()]);
        $this->assertNull($this->repositorio->findOwned($curso->id(), (string) $this->dono->getKey()));
    }

    public function test_curso_inexistente_e_curso_alheio_produzem_o_mesmo_nulo(): void
    {
        $alheio = $this->cursoDe($this->outro);
        $inexistente = (string) Uuid::uuid7();

        // A indistincao comeca na porta: quem chama recebe exatamente a mesma
        // coisa nos dois casos, entao nao tem como responder diferente.
        $this->assertSame(
            $this->repositorio->findOwned($alheio->id(), (string) $this->dono->getKey()),
            $this->repositorio->findOwned($inexistente, (string) $this->dono->getKey()),
        );
    }

    // -----------------------------------------------------------------------
    // Paginacao
    // -----------------------------------------------------------------------

    public function test_a_pagina_traz_somente_cursos_do_dono(): void
    {
        $this->cursosDe($this->dono, 3);
        $this->cursosDe($this->outro, 5);

        $pagina = $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 1, perPage: 15);

        $this->assertInstanceOf(Page::class, $pagina);
        $this->assertCount(3, $pagina->items);

        foreach ($pagina->items as $curso) {
            $this->assertInstanceOf(Course::class, $curso);
            $this->assertTrue($curso->isOwnedBy((string) $this->dono->getKey()));
        }
    }

    public function test_o_total_ignora_cursos_de_outro_produtor(): void
    {
        $this->cursosDe($this->dono, 2);
        $this->cursosDe($this->outro, 40);

        $pagina = $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 1, perPage: 15);

        // Se o filtro acontecesse depois de carregar, o total continuaria 42 e a
        // contagem sozinha ja diria quantos cursos o outro produtor tem.
        $this->assertSame(2, $pagina->total);
        $this->assertSame(1, $pagina->lastPage);
    }

    public function test_o_filtro_de_dono_esta_no_sql_da_contagem_e_da_leitura(): void
    {
        $this->cursosDe($this->dono, 2);
        $this->cursosDe($this->outro, 2);

        $consultas = $this->capturarConsultas(
            fn () => $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 1, perPage: 15),
        );

        $this->assertNotEmpty($consultas);

        // Toda consulta emitida — inclusive o `COUNT` da paginacao — carrega o
        // recorte. Nenhuma delas le a tabela inteira para filtrar depois.
        foreach ($consultas as $sql) {
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
        }

        $this->assertTrue(
            (bool) array_filter($consultas, fn (string $sql): bool => str_contains(strtolower($sql), 'count')),
        );
    }

    public function test_a_ordenacao_e_do_mais_recente_para_o_mais_antigo(): void
    {
        $antigo = $this->cursoDe($this->dono, '2026-09-01T10:00:00+00:00');
        $recente = $this->cursoDe($this->dono, '2026-09-03T10:00:00+00:00');
        $meio = $this->cursoDe($this->dono, '2026-09-02T10:00:00+00:00');

        $pagina = $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 1, perPage: 15);

        $this->assertSame(
            [$recente->id(), $meio->id(), $antigo->id()],
            array_map(fn (Course $c): string => $c->id(), $pagina->items),
        );
    }

    public function test_cursos_no_mesmo_instante_tem_ordem_estavel_pelo_identificador(): void
    {
        // O caso que o desempate existe para resolver: `created_at` tem precisao
        // de segundo, entao criacoes proximas empatam. Sem o segundo criterio, a
        // mesma consulta poderia devolver ordens diferentes, e um registro
        // apareceria em duas paginas ou em nenhuma.
        //
        // A afirmacao e sobre **estabilidade**, e nao sobre significado: o
        // identificador maior nao quer dizer "mais recente". O esperado e
        // calculado ordenando os identificadores, e nao a ordem de criacao.
        $mesmoInstante = '2026-09-02T10:00:00+00:00';
        $primeiro = $this->cursoDe($this->dono, $mesmoInstante);
        $segundo = $this->cursoDe($this->dono, $mesmoInstante);

        $esperado = [$primeiro->id(), $segundo->id()];
        rsort($esperado);

        for ($tentativa = 1; $tentativa <= 3; $tentativa++) {
            $pagina = $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 1, perPage: 15);

            $this->assertSame(
                $esperado,
                array_map(fn (Course $c): string => $c->id(), $pagina->items),
                "tentativa {$tentativa}",
            );
        }
    }

    public function test_a_paginacao_respeita_pagina_e_tamanho(): void
    {
        $this->cursosDe($this->dono, 7);

        $segunda = $this->repositorio->pageOfOwner((string) $this->dono->getKey(), page: 2, perPage: 3);

        $this->assertCount(3, $segunda->items);
        $this->assertSame(2, $segunda->currentPage);
        $this->assertSame(3, $segunda->perPage);
        $this->assertSame(7, $segunda->total);
        $this->assertSame(3, $segunda->lastPage);
    }

    // -----------------------------------------------------------------------
    // Leitura travada
    // -----------------------------------------------------------------------

    public function test_a_leitura_travada_emite_for_update(): void
    {
        $curso = $this->cursoDe($this->dono);

        $consultas = $this->capturarConsultas(function () use ($curso): void {
            DB::transaction(function () use ($curso): void {
                $this->repositorio->lockOwned($curso->id(), (string) $this->dono->getKey());
            });
        });

        $comTrava = array_filter(
            $consultas,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertNotEmpty($comTrava, 'a leitura travada precisa emitir FOR UPDATE');
    }

    public function test_a_leitura_travada_tambem_filtra_o_dono(): void
    {
        $alheio = $this->cursoDe($this->outro);

        // Sem este filtro, a leitura travada seria uma segunda porta de entrada
        // para curso alheio — e ainda por cima uma que segura recurso do banco.
        $this->assertNull($this->repositorio->lockOwned($alheio->id(), (string) $this->dono->getKey()));
    }

    public function test_a_leitura_travada_devolve_o_curso_proprio(): void
    {
        $curso = $this->cursoDe($this->dono);

        $travado = DB::transaction(
            fn (): ?Course => $this->repositorio->lockOwned($curso->id(), (string) $this->dono->getKey()),
        );

        $this->assertInstanceOf(Course::class, $travado);
        $this->assertSame($curso->id(), $travado->id());
    }

    // -----------------------------------------------------------------------
    // Eloquent nao atravessa a porta
    // -----------------------------------------------------------------------

    public function test_nenhum_metodo_da_porta_devolve_model_eloquent(): void
    {
        $curso = $this->cursoDe($this->dono);
        $dono = (string) $this->dono->getKey();

        $retornos = [
            'nextIdentity' => $this->repositorio->nextIdentity(),
            'findOwned' => $this->repositorio->findOwned($curso->id(), $dono),
            'pageOfOwner' => $this->repositorio->pageOfOwner($dono, 1, 15),
            'lockOwned' => DB::transaction(fn () => $this->repositorio->lockOwned($curso->id(), $dono)),
        ];

        foreach ($retornos as $metodo => $valor) {
            $this->assertNotInstanceOf(Model::class, $valor, $metodo);
        }

        foreach ($retornos['pageOfOwner']->items as $item) {
            $this->assertNotInstanceOf(Model::class, $item);
            $this->assertInstanceOf(Course::class, $item);
        }
    }

    public function test_a_assinatura_da_porta_nao_menciona_eloquent(): void
    {
        // A verificacao do tipo declarado, e nao apenas do valor devolvido: um
        // metodo pode devolver `Course` hoje e ter assinatura permissiva o
        // bastante para devolver um model amanha.
        foreach (['nextIdentity', 'findOwned', 'pageOfOwner', 'lockOwned'] as $metodo) {
            $tipo = (string) (new ReflectionMethod(CourseRepository::class, $metodo))->getReturnType();

            $this->assertStringNotContainsString('Illuminate', $tipo, $metodo);
            $this->assertStringNotContainsString(CourseModel::class, $tipo, $metodo);
        }
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function cursoDe(User $dono, string $criadoEm = '2026-09-05T13:45:12+00:00'): Course
    {
        $curso = Course::create(
            id: $this->repositorio->nextIdentity(),
            ownerId: (string) $dono->getKey(),
            title: 'Curso de '.$dono->name,
            description: 'Descricao do curso.',
            createdAt: new DateTimeImmutable($criadoEm),
        );

        $this->repositorio->save($curso);

        return $curso;
    }

    /**
     * @return list<Course>
     */
    private function cursosDe(User $dono, int $quantidade): array
    {
        $cursos = [];

        for ($i = 0; $i < $quantidade; $i++) {
            // Instantes distintos e crescentes, sem depender do calendario:
            // somar minutos evita o dia 41 quando a quantidade passa de 30.
            $cursos[] = $this->cursoDe(
                $dono,
                (new DateTimeImmutable('2026-09-01T10:00:00+00:00'))
                    ->modify("+{$i} minutes")
                    ->format(DATE_ATOM),
            );
        }

        return $cursos;
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
