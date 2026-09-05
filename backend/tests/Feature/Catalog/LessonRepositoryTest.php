<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Domain\Course;
use App\Catalog\Domain\Lesson;
use App\Catalog\Domain\Module;
use App\Catalog\Infrastructure\Persistence\Eloquent\LessonModel;
use App\Models\User;
use App\Video\Domain\VideoState;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * O adapter de aulas contra o MySQL de verdade.
 *
 * A cadeia da propriedade aqui e a mais longa do catalogo —
 * `lesson -> module -> course -> owner_id` —, e e ela que estes testes existem
 * para provar: uma aula de outro produtor nao pode ser alcancada, e a checagem
 * precisa acontecer **na consulta**, nao depois de carregar. Um repositorio em
 * memoria passaria em tudo isso sem que nada fosse verdade em execucao
 * (plan §17.2).
 */
final class LessonRepositoryTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private LessonRepository $repositorio;

    private User $dono;

    private User $outro;

    private Course $curso;

    private Module $modulo;

    private Module $moduloAlheio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositorio = $this->app->make(LessonRepository::class);
        $this->dono = User::factory()->producer()->create();
        $this->outro = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->dono);
        $this->modulo = $this->umModulo($this->curso);
        $this->moduloAlheio = $this->umModulo($this->umCurso($this->outro));
    }

    // -----------------------------------------------------------------------
    // Ida e volta
    // -----------------------------------------------------------------------

    public function test_salvar_e_reconstituir_devolve_a_mesma_aula(): void
    {
        $aula = Lesson::create(
            id: $this->repositorio->nextIdentity(),
            moduleId: $this->modulo->id(),
            title: 'Primeiros passos',
            position: 1,
        );

        $this->repositorio->save($aula);

        $relida = $this->repositorio->findOwned($aula->id(), (string) $this->dono->getKey());

        $this->assertInstanceOf(Lesson::class, $relida);
        $this->assertSame($aula->id(), $relida->id());
        $this->assertSame($this->modulo->id(), $relida->moduleId());
        $this->assertSame('Primeiros passos', $relida->title());
        $this->assertSame(1, $relida->position());
    }

    public function test_a_aula_gravada_nasce_rascunho_e_sem_video(): void
    {
        $aula = $this->comAulas(1)[0];

        $relida = $this->repositorio->findOwned($aula->id(), (string) $this->dono->getKey());

        $this->assertNotNull($relida);
        $this->assertNull($relida->publishedAt());
        $this->assertNull($relida->currentVideoAttemptId());
        $this->assertTrue($relida->isDraft());

        // E o banco concorda: nao basta o agregado dizer, a linha tambem precisa
        // ter nascido assim (RF-AUL-004, RF-AUL-005).
        $this->assertDatabaseHas('lessons', [
            'id' => $aula->id(),
            'published_at' => null,
            'current_video_attempt_id' => null,
        ]);
    }

    public function test_a_publicacao_sobrevive_a_ida_e_volta_como_data(): void
    {
        $aula = $this->comAulas(1)[0];
        $this->publicadaEm($aula, '2026-09-05T13:45:12+00:00');

        $relida = $this->repositorio->findOwned($aula->id(), (string) $this->dono->getKey());

        $this->assertNotNull($relida);
        $this->assertInstanceOf(DateTimeImmutable::class, $relida->publishedAt());
        $this->assertSame(
            '2026-09-05T13:45:12+00:00',
            $relida->publishedAt()?->format(DATE_ATOM),
        );
        $this->assertFalse($relida->isDraft());
    }

    public function test_a_tentativa_atual_sobrevive_a_ida_e_volta(): void
    {
        $aula = $this->comAulas(1)[0];
        $tentativa = $this->umaTentativaDeVideo($aula, VideoState::PROCESSING);

        $relida = $this->repositorio->findOwned($aula->id(), (string) $this->dono->getKey());

        // O agregado guarda **qual** e a tentativa, e nao em que estado ela esta:
        // o estado pertence a outro agregado (plan §6.1).
        $this->assertSame($tentativa, $relida?->currentVideoAttemptId());
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

    public function test_o_modulo_sem_aulas_comeca_na_posicao_um(): void
    {
        $this->assertSame(1, $this->repositorio->nextPosition($this->modulo->id()));
    }

    public function test_a_proxima_posicao_e_o_maior_valor_mais_um(): void
    {
        $this->comAulas(3);

        $this->assertSame(4, $this->repositorio->nextPosition($this->modulo->id()));
    }

    public function test_cada_modulo_tem_a_propria_sequencia(): void
    {
        $this->comAulas(3);
        $segundoModulo = $this->umModulo($this->curso, 'Segundo modulo');

        // Modulos do **mesmo curso** nao compartilham sequencia: a posicao e
        // dentro do modulo (RN-ORD-001).
        $this->assertSame(1, $this->repositorio->nextPosition($segundoModulo->id()));
    }

    // -----------------------------------------------------------------------
    // O dono e parte da consulta
    // -----------------------------------------------------------------------

    public function test_a_busca_encontra_a_aula_do_proprio_dono(): void
    {
        $aula = $this->comAulas(1)[0];

        $this->assertNotNull($this->repositorio->findOwned($aula->id(), (string) $this->dono->getKey()));
    }

    public function test_a_busca_nao_encontra_aula_de_outro_produtor(): void
    {
        $alheia = $this->umaAula($this->moduloAlheio);

        // A aula existe na tabela; ela so nao existe para este dono. A checagem
        // atravessa dois niveis — aula, modulo, curso — dentro do SQL.
        $this->assertDatabaseHas('lessons', ['id' => $alheia->id()]);
        $this->assertNull($this->repositorio->findOwned($alheia->id(), (string) $this->dono->getKey()));
    }

    public function test_aula_inexistente_e_aula_alheia_produzem_o_mesmo_nulo(): void
    {
        $alheia = $this->umaAula($this->moduloAlheio);
        $dono = (string) $this->dono->getKey();

        $this->assertSame(
            $this->repositorio->findOwned($alheia->id(), $dono),
            $this->repositorio->findOwned((string) Uuid::uuid7(), $dono),
        );
    }

    public function test_a_listagem_de_modulo_alheio_volta_vazia(): void
    {
        $this->umaAula($this->moduloAlheio);

        $this->assertSame(
            [],
            $this->repositorio->listOfOwnedModule($this->moduloAlheio->id(), (string) $this->dono->getKey()),
        );
    }

    public function test_o_filtro_de_dono_esta_no_sql_de_todas_as_leituras(): void
    {
        $aula = $this->comAulas(1)[0];
        $dono = (string) $this->dono->getKey();

        $consultas = $this->capturarConsultas(function () use ($aula, $dono): void {
            $this->repositorio->findOwned($aula->id(), $dono);
            $this->repositorio->listOfOwnedModule($this->modulo->id(), $dono);
        });

        $this->assertNotEmpty($consultas);

        foreach ($consultas as $sql) {
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
            // A cadeia inteira aparece na consulta: sem `modules` no meio, o
            // filtro estaria comparando a aula com o curso errado.
            $this->assertStringContainsString('`modules`', $sql, $sql);
            $this->assertStringContainsString('`courses`', $sql, $sql);
        }
    }

    // -----------------------------------------------------------------------
    // Ordem
    // -----------------------------------------------------------------------

    public function test_a_listagem_vem_ordenada_por_posicao(): void
    {
        $aulas = $this->comAulas(4);

        $lista = $this->repositorio->listOfOwnedModule($this->modulo->id(), (string) $this->dono->getKey());

        $this->assertSame(
            array_map(fn (Lesson $a): string => $a->id(), $aulas),
            array_map(fn (Lesson $a): string => $a->id(), $lista),
        );
        $this->assertSame([1, 2, 3, 4], array_map(fn (Lesson $a): int => $a->position(), $lista));
    }

    public function test_duas_leituras_consecutivas_sem_escrita_devolvem_a_mesma_sequencia(): void
    {
        // RN-ORD-004: a ordem e total e deterministica. Sem `ORDER BY`, o MySQL
        // pode devolver ordens diferentes para a mesma consulta.
        $this->comAulas(5);
        $dono = (string) $this->dono->getKey();

        $primeira = $this->repositorio->listOfOwnedModule($this->modulo->id(), $dono);
        $segunda = $this->repositorio->listOfOwnedModule($this->modulo->id(), $dono);

        $this->assertEquals($primeira, $segunda);
    }

    // -----------------------------------------------------------------------
    // A ultima linha de defesa
    // -----------------------------------------------------------------------

    public function test_a_unique_recusa_duas_posicoes_iguais_no_mesmo_modulo(): void
    {
        $this->comAulas(1);

        $this->expectException(QueryException::class);

        DB::table('lessons')->insert([
            'id' => (string) Uuid::uuid7(),
            'module_id' => $this->modulo->id(),
            'title' => 'Duplicata',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_mesma_posicao_e_permitida_em_modulos_diferentes(): void
    {
        $this->comAulas(1);
        $outroModulo = $this->umModulo($this->curso, 'Segundo modulo');

        $this->assertSame(1, $this->umaAula($outroModulo)->position());
    }

    // -----------------------------------------------------------------------
    // Eloquent nao atravessa a porta
    // -----------------------------------------------------------------------

    public function test_nenhum_metodo_da_porta_devolve_model_eloquent(): void
    {
        $aula = $this->comAulas(1)[0];
        $dono = (string) $this->dono->getKey();

        $retornos = [
            'nextIdentity' => $this->repositorio->nextIdentity(),
            'nextPosition' => $this->repositorio->nextPosition($this->modulo->id()),
            'findOwned' => $this->repositorio->findOwned($aula->id(), $dono),
            'listOfOwnedModule' => $this->repositorio->listOfOwnedModule($this->modulo->id(), $dono),
        ];

        foreach ($retornos as $metodo => $valor) {
            $this->assertNotInstanceOf(Model::class, $valor, $metodo);
        }

        foreach ($retornos['listOfOwnedModule'] as $item) {
            $this->assertNotInstanceOf(Model::class, $item);
            $this->assertInstanceOf(Lesson::class, $item);
        }
    }

    public function test_a_assinatura_da_porta_nao_menciona_eloquent(): void
    {
        foreach (['nextIdentity', 'save', 'findOwned', 'nextPosition', 'listOfOwnedModule'] as $metodo) {
            $reflexao = new ReflectionMethod(LessonRepository::class, $metodo);
            $tipos = [(string) $reflexao->getReturnType()];

            foreach ($reflexao->getParameters() as $parametro) {
                $tipos[] = (string) $parametro->getType();
            }

            foreach ($tipos as $tipo) {
                $this->assertStringNotContainsString('Illuminate', $tipo, $metodo);
                $this->assertStringNotContainsString(LessonModel::class, $tipo, $metodo);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * @return list<Lesson>
     */
    private function comAulas(int $quantidade): array
    {
        $aulas = [];

        for ($i = 1; $i <= $quantidade; $i++) {
            $aulas[] = $this->umaAula($this->modulo, "Aula {$i}");
        }

        return $aulas;
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
