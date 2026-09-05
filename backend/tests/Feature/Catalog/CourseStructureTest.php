<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Domain\Course;
use App\Models\User;
use App\Video\Domain\VideoState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/courses/{course}/structure`.
 *
 * A arvore inteira em uma leitura (RF-EST-001), com a ordem preservada nos dois
 * niveis (RF-EST-002), sem conteudo de outro produtor (RF-EST-003) e incluindo
 * rascunhos e o estado do video de cada aula (RF-EST-004).
 *
 * Dois testes deste arquivo nao sao sobre o corpo da resposta e sim sobre o custo
 * dela: a arvore precisa sair em um numero **fixo** de consultas, e nao em uma
 * por modulo. E a diferenca entre uma leitura util e uma que piora conforme o
 * curso cresce.
 */
final class CourseStructureTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private User $produtor;

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();

        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor, 'Fundamentos de PHP');
    }

    // -----------------------------------------------------------------------
    // Forma da resposta
    // -----------------------------------------------------------------------

    public function test_a_estrutura_traz_o_curso_com_os_mesmos_campos_da_consulta_individual(): void
    {
        $doEndpointDeCurso = $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$this->curso->id()}")
            ->json('data');

        $daEstrutura = $this->estrutura()->json('data');

        // O curso tem uma forma so, decidida em um lugar so: a estrutura
        // acrescenta `modules` e nao muda mais nada.
        foreach ($doEndpointDeCurso as $campo => $valor) {
            $this->assertSame($valor, $daEstrutura[$campo], $campo);
        }

        $this->assertSame(
            ['id', 'title', 'description', 'owner_id', 'state', 'created_at', 'modules'],
            array_keys($daEstrutura),
        );
    }

    public function test_cada_modulo_traz_os_quatro_campos_mais_as_aulas(): void
    {
        $modulo = $this->umModulo($this->curso);
        $this->umaAula($modulo);

        $this->assertSame(
            ['id', 'course_id', 'title', 'position', 'lessons'],
            array_keys($this->estrutura()->json('data.modules.0')),
        );
    }

    public function test_cada_aula_traz_os_seis_campos_do_contrato(): void
    {
        $this->umaAula($this->umModulo($this->curso));

        $this->assertSame(
            ['id', 'module_id', 'title', 'position', 'published_at', 'video_state'],
            array_keys($this->estrutura()->json('data.modules.0.lessons.0')),
        );
    }

    public function test_a_arvore_nao_tem_meta_nem_links(): void
    {
        // A estrutura nao e paginada: paginar uma arvore quebraria a ordem que a
        // spec exige preservar (plan §10.1, RF-EST-002).
        $this->umaAula($this->umModulo($this->curso));

        $this->assertSame(['data'], array_keys($this->estrutura()->json()));
    }

    // -----------------------------------------------------------------------
    // Ordem nos dois niveis
    // -----------------------------------------------------------------------

    public function test_modulos_e_aulas_saem_na_ordem_de_posicao(): void
    {
        foreach (['Primeiro', 'Segundo', 'Terceiro'] as $tituloModulo) {
            $modulo = $this->umModulo($this->curso, $tituloModulo);

            foreach (['Aula A', 'Aula B'] as $tituloAula) {
                $this->umaAula($modulo, "{$tituloModulo} - {$tituloAula}");
            }
        }

        $modulos = $this->estrutura()->json('data.modules');

        $this->assertSame([1, 2, 3], array_column($modulos, 'position'));
        $this->assertSame(['Primeiro', 'Segundo', 'Terceiro'], array_column($modulos, 'title'));

        foreach ($modulos as $modulo) {
            $this->assertSame([1, 2], array_column($modulo['lessons'], 'position'));
        }
    }

    public function test_duas_leituras_consecutivas_sem_escrita_devolvem_a_mesma_arvore(): void
    {
        // RN-ORD-004. Sem `ORDER BY`, o MySQL pode devolver ordens diferentes para
        // a mesma consulta, e a arvore mudaria de forma entre duas leituras.
        for ($i = 1; $i <= 3; $i++) {
            $modulo = $this->umModulo($this->curso, "Modulo {$i}");

            for ($j = 1; $j <= 3; $j++) {
                $this->umaAula($modulo, "Aula {$i}.{$j}");
            }
        }

        $this->assertSame($this->estrutura()->json('data'), $this->estrutura()->json('data'));
    }

    public function test_as_aulas_ficam_dentro_do_modulo_a_que_pertencem(): void
    {
        $primeiro = $this->umModulo($this->curso, 'Primeiro');
        $segundo = $this->umModulo($this->curso, 'Segundo');

        $this->umaAula($primeiro, 'Do primeiro');
        $this->umaAula($segundo, 'Do segundo');

        $modulos = $this->estrutura()->json('data.modules');

        $this->assertSame('Do primeiro', $modulos[0]['lessons'][0]['title']);
        $this->assertSame($primeiro->id(), $modulos[0]['lessons'][0]['module_id']);
        $this->assertSame('Do segundo', $modulos[1]['lessons'][0]['title']);
        $this->assertSame($segundo->id(), $modulos[1]['lessons'][0]['module_id']);
    }

    // -----------------------------------------------------------------------
    // Vazios
    // -----------------------------------------------------------------------

    public function test_curso_sem_modulos_devolve_lista_vazia(): void
    {
        $resposta = $this->estrutura();

        $resposta->assertOk();
        $this->assertSame([], $resposta->json('data.modules'));
    }

    public function test_modulo_sem_aulas_devolve_lista_vazia(): void
    {
        $this->umModulo($this->curso);

        // Ausencia de conteudo nao e ausencia de modulo: a chave existe e a lista
        // esta vazia.
        $this->assertSame([], $this->estrutura()->json('data.modules.0.lessons'));
    }

    // -----------------------------------------------------------------------
    // Rascunhos e estado do video (RF-EST-004)
    // -----------------------------------------------------------------------

    public function test_a_estrutura_do_produtor_inclui_rascunhos(): void
    {
        $modulo = $this->umModulo($this->curso);
        $rascunho = $this->umaAula($modulo, 'Ainda rascunho');
        $publicada = $this->umaAula($modulo, 'Ja publicada');
        $this->publicadaEm($publicada, '2026-09-05T13:45:12+00:00');

        $aulas = $this->estrutura()->json('data.modules.0.lessons');

        // As duas aparecem. A visao do produtor precisa acompanhar o que ainda
        // nao foi publicado; a do consumidor, que chega depois, e que filtra.
        $this->assertCount(2, $aulas);
        $this->assertSame($rascunho->id(), $aulas[0]['id']);
        $this->assertNull($aulas[0]['published_at']);
        $this->assertSame('2026-09-05T13:45:12+00:00', $aulas[1]['published_at']);
    }

    public function test_o_estado_do_video_e_nulo_quando_nao_ha_tentativa(): void
    {
        $this->umaAula($this->umModulo($this->curso));

        $this->assertNull($this->estrutura()->json('data.modules.0.lessons.0.video_state'));
    }

    public function test_o_cenario_com_tentativa_em_processamento_devolve_processing(): void
    {
        $modulo = $this->umModulo($this->curso);
        $semVideo = $this->umaAula($modulo, 'Sem video');
        $comVideo = $this->umaAula($modulo, 'Com video em processamento');
        $this->umaTentativaDeVideo($comVideo, VideoState::PROCESSING);

        $aulas = $this->estrutura()->json('data.modules.0.lessons');

        // As duas convivem na mesma arvore: a juncao com a tentativa e **a
        // esquerda**, e uma juncao interna faria a aula sem video sumir.
        $this->assertSame($semVideo->id(), $aulas[0]['id']);
        $this->assertNull($aulas[0]['video_state']);
        $this->assertSame($comVideo->id(), $aulas[1]['id']);
        $this->assertSame('processing', $aulas[1]['video_state']);
    }

    // -----------------------------------------------------------------------
    // Isolamento entre produtores (RF-EST-003)
    // -----------------------------------------------------------------------

    public function test_a_estrutura_de_curso_alheio_responde_404(): void
    {
        $outro = User::factory()->producer()->create();
        $cursoAlheio = $this->umCurso($outro);
        $this->umaAula($this->umModulo($cursoAlheio));

        $resposta = $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$cursoAlheio->id()}/structure");

        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_curso_alheio_e_curso_inexistente_respondem_identicamente(): void
    {
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());

        $alheio = $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$cursoAlheio->id()}/structure");
        $ausente = $this->actingAs($this->produtor)
            ->getJson('/api/courses/'.(string) Uuid::uuid7().'/structure');

        $this->assertSame($alheio->getStatusCode(), $ausente->getStatusCode());
        $this->assertSame($alheio->getContent(), $ausente->getContent());
        $this->assertSame(
            $alheio->headers->get('content-type'),
            $ausente->headers->get('content-type'),
        );
    }

    public function test_a_arvore_nao_traz_conteudo_de_outro_curso(): void
    {
        $meuModulo = $this->umModulo($this->curso, 'Deste curso');
        $this->umaAula($meuModulo, 'Desta arvore');

        $outroCurso = $this->umCurso($this->produtor, 'Outro curso meu');
        $this->umaAula($this->umModulo($outroCurso, 'De outro curso'), 'De outra arvore');

        $corpo = (string) $this->estrutura()->getContent();

        $this->assertStringContainsString('Desta arvore', $corpo);
        $this->assertStringNotContainsString('De outra arvore', $corpo);
        $this->assertStringNotContainsString('De outro curso', $corpo);
    }

    public function test_o_filtro_de_dono_aparece_em_todas_as_consultas_da_arvore(): void
    {
        $this->umaAula($this->umModulo($this->curso));

        $consultas = $this->consultasDaEstrutura();

        $doCatalogo = array_filter(
            $consultas,
            fn (string $sql): bool => (bool) preg_match('/from `(courses|modules|lessons)`/', $sql),
        );

        $this->assertNotEmpty($doCatalogo);

        // Cada uma das tres consultas alcanca `courses.owner_id` sozinha. E
        // redundante — o curso ja foi resolvido pelo dono —, e a redundancia e o
        // ponto: nenhuma delas devolve conteudo alheio se for lida ou
        // reaproveitada isoladamente.
        foreach ($doCatalogo as $sql) {
            $this->assertStringContainsString('`owner_id` = ?', $sql, $sql);
        }
    }

    // -----------------------------------------------------------------------
    // Custo: nenhum N+1
    // -----------------------------------------------------------------------

    public function test_a_arvore_sai_em_tres_consultas_de_catalogo(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $modulo = $this->umModulo($this->curso, "Modulo {$i}");

            for ($j = 1; $j <= 3; $j++) {
                $this->umaAula($modulo, "Aula {$i}.{$j}");
            }
        }

        $consultas = array_values(array_filter(
            $this->consultasDaEstrutura(),
            fn (string $sql): bool => (bool) preg_match('/from `(courses|modules|lessons)`/', $sql),
        ));

        // Uma para o curso, uma para os modulos, uma para todas as aulas — quatro
        // modulos e doze aulas nao acrescentam nenhuma.
        $this->assertCount(3, $consultas, implode("\n", $consultas));
    }

    public function test_o_numero_de_consultas_nao_cresce_com_o_tamanho_do_curso(): void
    {
        // A prova comparativa, que nao depende de acertar o numero exato: o custo
        // de um curso com um modulo e o mesmo de um com dez. Se houvesse uma
        // consulta por modulo, a diferenca seria de nove.
        $this->umaAula($this->umModulo($this->curso, 'Unico'));
        $comUmModulo = count($this->consultasDaEstrutura());

        $grande = $this->umCurso($this->produtor, 'Curso grande');

        for ($i = 1; $i <= 10; $i++) {
            $modulo = $this->umModulo($grande, "Modulo {$i}");

            for ($j = 1; $j <= 5; $j++) {
                $this->umaAula($modulo, "Aula {$i}.{$j}");
            }
        }

        $comDezModulos = count($this->consultasDaEstrutura($grande->id()));

        $this->assertSame($comUmModulo, $comDezModulos);
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    private function estrutura(?string $cursoId = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->produtor)
            ->getJson('/api/courses/'.($cursoId ?? $this->curso->id()).'/structure');
    }

    /**
     * @return list<string>
     */
    private function consultasDaEstrutura(?string $cursoId = null): array
    {
        $consultas = [];

        DB::listen(function ($evento) use (&$consultas): void {
            $consultas[] = $evento->sql;
        });

        $this->estrutura($cursoId)->assertOk();

        DB::flushQueryLog();

        return $consultas;
    }
}
