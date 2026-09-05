<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Module;
use App\Catalog\Infrastructure\Persistence\Eloquent\ModuleModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * O adapter de modulos contra o MySQL de verdade.
 *
 * Contra o banco real, e nao contra um dublê, porque o que precisa ser provado
 * aqui e justamente o que um dublê nao teria: que a cadeia da propriedade e
 * percorrida **dentro do SQL**, que a leitura travada emite `FOR UPDATE`, e que a
 * UNIQUE `(course_id, position)` recusa a duplicata mesmo quando tudo o mais
 * falha. Um repositorio em memoria passaria em todos esses testes sem que nada
 * disso fosse verdade em execucao (plan §17.2).
 */
final class ModuleRepositoryTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private ModuleRepository $repositorio;

    private User $dono;

    private User $outro;

    private Course $curso;

    private Course $cursoAlheio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositorio = $this->app->make(ModuleRepository::class);
        $this->dono = User::factory()->producer()->create();
        $this->outro = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->dono);
        $this->cursoAlheio = $this->umCurso($this->outro);
    }

    // -----------------------------------------------------------------------
    // Ida e volta
    // -----------------------------------------------------------------------

    public function test_salvar_e_reconstituir_devolve_o_mesmo_modulo(): void
    {
        $modulo = Module::create(
            id: $this->repositorio->nextIdentity(),
            courseId: $this->curso->id(),
            title: 'Introducao',
            position: 1,
        );

        $this->repositorio->save($modulo);

        $relido = DB::transaction(
            fn (): ?Module => $this->repositorio->lockOwned($modulo->id(), (string) $this->dono->getKey()),
        );

        $this->assertInstanceOf(Module::class, $relido);
        $this->assertSame($modulo->id(), $relido->id());
        $this->assertSame($this->curso->id(), $relido->courseId());
        $this->assertSame('Introducao', $relido->title());
        $this->assertSame(1, $relido->position());
    }

    public function test_a_posicao_volta_do_banco_como_inteiro(): void
    {
        // Sem o cast do model, o MySQL devolveria a coluna como string e o
        // agregado recusaria o valor no construtor tipado — uma falha que so
        // apareceria na leitura, longe da escrita que a causou.
        $modulos = $this->comModulos(1);

        $this->assertIsInt($modulos[0]->position());
    }

    public function test_o_identificador_gerado_e_um_uuid_versao_7(): void
    {
        $identificador = $this->repositorio->nextIdentity();

        $this->assertTrue(Uuid::isValid($identificador));
        $this->assertSame(7, Uuid::fromString($identificador)->getFields()->getVersion());
    }

    // -----------------------------------------------------------------------
    // A proxima posicao
    // -----------------------------------------------------------------------

    public function test_o_curso_sem_modulos_comeca_na_posicao_um(): void
    {
        $this->assertSame(1, $this->repositorio->nextPosition($this->curso->id()));
    }

    public function test_a_proxima_posicao_e_o_maior_valor_mais_um(): void
    {
        $this->comModulos(3);

        $this->assertSame(4, $this->repositorio->nextPosition($this->curso->id()));
    }

    public function test_a_proxima_posicao_conta_apenas_o_curso_informado(): void
    {
        $this->comModulos(3);

        // O outro curso tem modulos proprios, e a sequencia de um nao interfere na
        // do outro: cada curso tem a sua (RN-ORD-002).
        $this->assertSame(1, $this->repositorio->nextPosition($this->cursoAlheio->id()));
    }

    public function test_a_proxima_posicao_usa_o_maximo_e_nao_a_contagem(): void
    {
        // A diferenca so aparece quando falta um numero no meio. `COUNT(*)`
        // devolveria 3 e repetiria a posicao 3, que ja existe; `MAX(position)+1`
        // devolve 4. A situacao e alcancavel por exclusao em cascata ou correcao
        // manual — nao e hipotetica.
        $this->comModulos(3);
        DB::table('modules')->where('course_id', $this->curso->id())->where('position', 2)->delete();

        $this->assertSame(3, DB::table('modules')->where('course_id', $this->curso->id())->max('position'));
        $this->assertSame(4, $this->repositorio->nextPosition($this->curso->id()));
    }

    // -----------------------------------------------------------------------
    // O dono e parte da consulta
    // -----------------------------------------------------------------------

    public function test_a_leitura_travada_encontra_o_modulo_do_proprio_dono(): void
    {
        $modulo = $this->comModulos(1)[0];

        $this->assertNotNull(DB::transaction(
            fn () => $this->repositorio->lockOwned($modulo->id(), (string) $this->dono->getKey()),
        ));
    }

    public function test_a_leitura_travada_nao_encontra_modulo_de_outro_produtor(): void
    {
        $alheio = $this->umModulo($this->cursoAlheio);

        // O modulo existe na tabela; ele so nao existe para este dono.
        $this->assertDatabaseHas('modules', ['id' => $alheio->id()]);
        $this->assertNull(DB::transaction(
            fn () => $this->repositorio->lockOwned($alheio->id(), (string) $this->dono->getKey()),
        ));
    }

    public function test_modulo_inexistente_e_modulo_alheio_produzem_o_mesmo_nulo(): void
    {
        $alheio = $this->umModulo($this->cursoAlheio);
        $dono = (string) $this->dono->getKey();

        // A indistincao comeca na porta: quem chama recebe exatamente a mesma
        // coisa nos dois casos, entao nao tem como responder diferente.
        $this->assertSame(
            DB::transaction(fn () => $this->repositorio->lockOwned($alheio->id(), $dono)),
            DB::transaction(fn () => $this->repositorio->lockOwned((string) Uuid::uuid7(), $dono)),
        );
    }

    public function test_a_listagem_traz_somente_modulos_do_dono(): void
    {
        $this->comModulos(2);
        $this->umModulo($this->cursoAlheio);

        $lista = $this->repositorio->listOfOwnedCourse($this->curso->id(), (string) $this->dono->getKey());

        $this->assertCount(2, $lista);
    }

    public function test_a_listagem_de_curso_alheio_volta_vazia(): void
    {
        $this->umModulo($this->cursoAlheio);

        $this->assertSame(
            [],
            $this->repositorio->listOfOwnedCourse($this->cursoAlheio->id(), (string) $this->dono->getKey()),
        );
    }

    public function test_o_filtro_de_dono_esta_no_sql_de_todas_as_leituras(): void
    {
        $modulo = $this->comModulos(1)[0];
        $dono = (string) $this->dono->getKey();

        $consultas = $this->capturarConsultas(function () use ($modulo, $dono): void {
            $this->repositorio->listOfOwnedCourse($this->curso->id(), $dono);
            DB::transaction(fn () => $this->repositorio->lockOwned($modulo->id(), $dono));
        });

        $this->assertNotEmpty($consultas);

        // O dono nao esta na tabela `modules`: ele mora em `courses.owner_id`, e a
        // consulta precisa alcanca-lo sozinha. Nenhuma destas leituras carrega
        // registros para filtrar depois em PHP.
        foreach ($consultas as $sql) {
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
            $this->assertStringContainsString('`courses`', $sql, $sql);
        }
    }

    // -----------------------------------------------------------------------
    // Ordem
    // -----------------------------------------------------------------------

    public function test_a_listagem_vem_ordenada_por_posicao(): void
    {
        $modulos = $this->comModulos(4);
        $esperado = array_map(fn (Module $m): string => $m->id(), $modulos);

        $lista = $this->repositorio->listOfOwnedCourse($this->curso->id(), (string) $this->dono->getKey());

        $this->assertSame($esperado, array_map(fn (Module $m): string => $m->id(), $lista));
        $this->assertSame([1, 2, 3, 4], array_map(fn (Module $m): int => $m->position(), $lista));
    }

    public function test_a_ordem_vem_do_sql_e_nao_do_php(): void
    {
        $this->comModulos(3);

        $consultas = $this->capturarConsultas(
            fn () => $this->repositorio->listOfOwnedCourse($this->curso->id(), (string) $this->dono->getKey()),
        );

        // A afirmacao e sobre **onde** a ordenacao acontece. Ordenar depois, em
        // PHP, daria o mesmo resultado para este chamador; pedir ao banco e o que
        // faz a garantia valer para todos eles, inclusive os que vierem depois.
        $this->assertNotEmpty(array_filter(
            $consultas,
            fn (string $sql): bool => str_contains(strtolower($sql), 'order by `position` asc'),
        ));
    }

    // -----------------------------------------------------------------------
    // A trava
    // -----------------------------------------------------------------------

    public function test_a_leitura_travada_emite_for_update(): void
    {
        $modulo = $this->comModulos(1)[0];

        $consultas = $this->capturarConsultas(function () use ($modulo): void {
            DB::transaction(function () use ($modulo): void {
                $this->repositorio->lockOwned($modulo->id(), (string) $this->dono->getKey());
            });
        });

        $comTrava = array_filter(
            $consultas,
            fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertNotEmpty($comTrava, 'a leitura travada precisa emitir FOR UPDATE');

        // A trava e sobre `modules`, o pai da aula. O recorte por dono vem de uma
        // subconsulta `EXISTS`, e nao de um `JOIN`, exatamente para que a linha do
        // curso **nao** seja travada junto: travar o curso serializaria criacoes
        // de aula em modulos diferentes do mesmo curso, que nao disputam
        // sequencia nenhuma.
        foreach ($comTrava as $sql) {
            $this->assertStringContainsString('from `modules`', strtolower($sql), $sql);
            $this->assertStringNotContainsString('inner join', strtolower($sql), $sql);
        }
    }

    // -----------------------------------------------------------------------
    // A ultima linha de defesa
    // -----------------------------------------------------------------------

    public function test_a_unique_recusa_duas_posicoes_iguais_no_mesmo_curso(): void
    {
        // A regra normal e o calculo sob trava (plan §8.1). Esta afirmacao e sobre
        // o que sobra quando ela falha: a escrita direta, ignorando o caso de uso,
        // continua sendo recusada pelo banco (RN-ORD-003).
        $this->comModulos(1);

        $this->expectException(QueryException::class);

        DB::table('modules')->insert([
            'id' => (string) Uuid::uuid7(),
            'course_id' => $this->curso->id(),
            'title' => 'Duplicata',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_mesma_posicao_e_permitida_em_cursos_diferentes(): void
    {
        // O par positivo: a UNIQUE e por curso, e nao global. Sem esta afirmacao,
        // uma restricao apenas sobre `position` passaria no teste acima.
        $this->comModulos(1);
        $noOutroCurso = $this->umModulo($this->cursoAlheio);

        $this->assertSame(1, $noOutroCurso->position());
    }

    public function test_o_banco_recusa_posicao_zero(): void
    {
        // O buraco que `UNSIGNED` deixa, fechado pelo `CHECK`. O agregado tambem
        // recusa, mas as duas defesas existem para caminhos diferentes: uma para
        // o codigo, outra para a escrita direta.
        $this->expectException(QueryException::class);

        DB::table('modules')->insert([
            'id' => (string) Uuid::uuid7(),
            'course_id' => $this->curso->id(),
            'title' => 'Posicao zero',
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Eloquent nao atravessa a porta
    // -----------------------------------------------------------------------

    public function test_nenhum_metodo_da_porta_devolve_model_eloquent(): void
    {
        $modulo = $this->comModulos(1)[0];
        $dono = (string) $this->dono->getKey();

        $retornos = [
            'nextIdentity' => $this->repositorio->nextIdentity(),
            'nextPosition' => $this->repositorio->nextPosition($this->curso->id()),
            'lockOwned' => DB::transaction(fn () => $this->repositorio->lockOwned($modulo->id(), $dono)),
            'listOfOwnedCourse' => $this->repositorio->listOfOwnedCourse($this->curso->id(), $dono),
        ];

        foreach ($retornos as $metodo => $valor) {
            $this->assertNotInstanceOf(Model::class, $valor, $metodo);
        }

        foreach ($retornos['listOfOwnedCourse'] as $item) {
            $this->assertNotInstanceOf(Model::class, $item);
            $this->assertInstanceOf(Module::class, $item);
        }
    }

    public function test_a_assinatura_da_porta_nao_menciona_eloquent(): void
    {
        foreach (['nextIdentity', 'save', 'lockOwned', 'nextPosition', 'listOfOwnedCourse'] as $metodo) {
            $reflexao = new ReflectionMethod(ModuleRepository::class, $metodo);
            $tipos = [(string) $reflexao->getReturnType()];

            foreach ($reflexao->getParameters() as $parametro) {
                $tipos[] = (string) $parametro->getType();
            }

            foreach ($tipos as $tipo) {
                $this->assertStringNotContainsString('Illuminate', $tipo, $metodo);
                $this->assertStringNotContainsString(ModuleModel::class, $tipo, $metodo);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * @return list<Module>
     */
    private function comModulos(int $quantidade): array
    {
        $modulos = [];

        for ($i = 1; $i <= $quantidade; $i++) {
            $modulos[] = $this->umModulo($this->curso, "Modulo {$i}");
        }

        return $modulos;
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
