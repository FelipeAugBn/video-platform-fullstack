<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Domain\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/courses/{course}/modules`.
 *
 * Tres coisas precisam valer ao mesmo tempo: a ordem e a de posicao, a lista
 * contem apenas o curso pedido, e o curso precisa ser do produtor autenticado —
 * com curso alheio respondendo o mesmo `404` de curso inexistente (RF-MOD-003,
 * RN-ORD-004, RN-PROP-005).
 *
 * A resposta **nao** e paginada: `data` e nada mais.
 */
final class ModuleListTest extends TestCase
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
    // Conteudo e forma
    // -----------------------------------------------------------------------

    public function test_a_listagem_devolve_os_modulos_do_curso(): void
    {
        $this->umModulo($this->curso, 'Primeiro');
        $this->umModulo($this->curso, 'Segundo');

        $resposta = $this->listar();

        $resposta->assertOk();
        $resposta->assertJsonCount(2, 'data');
        $this->assertSame(['Primeiro', 'Segundo'], array_column($resposta->json('data'), 'title'));
    }

    public function test_cada_item_traz_exatamente_os_quatro_campos_do_contrato(): void
    {
        $this->umModulo($this->curso);

        $this->assertSame(
            ['id', 'course_id', 'title', 'position'],
            array_keys($this->listar()->json('data.0')),
        );
    }

    public function test_a_resposta_nao_tem_meta_nem_links(): void
    {
        // A listagem de modulos nao e paginada (plan §10.1). `meta` e `links`
        // aqui dariam ao cliente a impressao de que existe uma segunda pagina de
        // uma ordem que precisa ser lida inteira.
        $this->umModulo($this->curso);

        $corpo = $this->listar()->json();

        $this->assertSame(['data'], array_keys($corpo));
    }

    public function test_curso_sem_modulos_devolve_colecao_vazia(): void
    {
        $resposta = $this->listar();

        // Colecao vazia e `200` com `data: []`, e nao `404`: o curso existe, ele
        // so nao tem modulos ainda.
        $resposta->assertOk();
        $this->assertSame([], $resposta->json('data'));
    }

    // -----------------------------------------------------------------------
    // Ordem
    // -----------------------------------------------------------------------

    public function test_a_ordem_e_a_de_posicao(): void
    {
        foreach (['Primeiro', 'Segundo', 'Terceiro', 'Quarto'] as $titulo) {
            $this->umModulo($this->curso, $titulo);
        }

        $this->assertSame([1, 2, 3, 4], array_column($this->listar()->json('data'), 'position'));
    }

    public function test_duas_leituras_consecutivas_sem_escrita_devolvem_a_mesma_sequencia(): void
    {
        // RN-ORD-004: a ordem e total e deterministica. Sem `ORDER BY`, o MySQL
        // pode devolver ordens diferentes para a mesma consulta, e um item
        // apareceria em posicoes distintas entre duas leituras.
        for ($i = 1; $i <= 5; $i++) {
            $this->umModulo($this->curso, "Modulo {$i}");
        }

        $primeira = $this->listar()->json('data');
        $segunda = $this->listar()->json('data');

        $this->assertSame($primeira, $segunda);
    }

    // -----------------------------------------------------------------------
    // Isolamento entre produtores
    // -----------------------------------------------------------------------

    public function test_a_listagem_nao_traz_modulos_de_outro_curso(): void
    {
        $this->umModulo($this->curso, 'Deste curso');
        $this->umModulo($this->umCurso($this->produtor, 'Outro curso'), 'De outro curso');

        $resposta = $this->listar();

        $resposta->assertJsonCount(1, 'data');
        $this->assertSame('Deste curso', $resposta->json('data.0.title'));
    }

    public function test_curso_de_outro_produtor_responde_404(): void
    {
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());
        $this->umModulo($cursoAlheio, 'Modulo alheio');

        $resposta = $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$cursoAlheio->id()}/modules");

        // `404`, e nao uma lista vazia: uma lista vazia diria que o curso existe
        // e apenas nao tem modulos, o que ja e informacao sobre recurso alheio
        // (RN-PROP-005).
        $resposta->assertNotFound();
        $resposta->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_curso_alheio_e_curso_inexistente_respondem_identicamente(): void
    {
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());
        $inexistente = (string) Uuid::uuid7();

        $alheio = $this->actingAs($this->produtor)->getJson("/api/courses/{$cursoAlheio->id()}/modules");
        $ausente = $this->actingAs($this->produtor)->getJson("/api/courses/{$inexistente}/modules");

        $this->assertSame($alheio->getStatusCode(), $ausente->getStatusCode());
        $this->assertSame($alheio->getContent(), $ausente->getContent());
        $this->assertSame(
            $alheio->headers->get('content-type'),
            $ausente->headers->get('content-type'),
        );
    }

    public function test_um_curso_proprio_vazio_e_um_curso_alheio_respondem_diferente(): void
    {
        // O par que fecha o raciocinio anterior: o `404` do curso alheio nao pode
        // ser confundido com o `200` vazio do curso proprio. Se os dois fossem
        // iguais, o isolamento estaria certo mas a jornada do produtor estaria
        // quebrada.
        $cursoAlheio = $this->umCurso(User::factory()->producer()->create());

        $this->listar()->assertOk();
        $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$cursoAlheio->id()}/modules")
            ->assertNotFound();
    }

    private function listar(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->produtor)->getJson("/api/courses/{$this->curso->id()}/modules");
    }
}
