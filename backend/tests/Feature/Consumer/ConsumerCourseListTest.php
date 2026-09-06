<?php

declare(strict_types=1);

namespace Tests\Feature\Consumer;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * `GET /api/catalog/courses`.
 *
 * Duas condicoes se somam aqui, e a segunda costuma ser esquecida: alem de a
 * lista trazer somente cursos **concedidos**, ela traz somente os que estao em
 * `available` (RF-CONS-001, RF-CONS-002). Um curso concedido ainda em rascunho
 * nao tem nada a consumir, e exibi-lo produziria uma tela vazia sem explicacao.
 *
 * E, como na listagem do produtor, os **numeros** tambem precisam obedecer ao
 * recorte. Uma implementacao que filtrasse depois de carregar passaria na
 * primeira afirmacao e falharia na contagem — e, em producao, o `total` sozinho
 * ja diria quantos cursos existem fora da concessao (RN-PROP-005).
 */
final class ConsumerCourseListTest extends TestCase
{
    use CatalogoDeTeste, RefreshDatabase;

    private User $consumidor;

    private User $produtor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumidor = User::factory()->consumer()->create();
        $this->produtor = User::factory()->producer()->create();
    }

    // -----------------------------------------------------------------------
    // O recorte
    // -----------------------------------------------------------------------

    public function test_a_lista_traz_somente_cursos_concedidos(): void
    {
        $concedido = $this->cursoDisponivel('Concedido');
        $this->concedidoA($concedido, $this->consumidor);

        $this->cursoDisponivel('Sem concessao');
        $this->cursoDisponivel('Tambem sem concessao');

        $resposta = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses');

        $resposta->assertOk();
        $resposta->assertJsonCount(1, 'data');
        $this->assertSame([$concedido->id()], array_column($resposta->json('data'), 'id'));
    }

    public function test_a_lista_ignora_curso_concedido_ainda_em_rascunho(): void
    {
        $disponivel = $this->cursoDisponivel('Disponivel');
        $rascunho = $this->umCurso($this->produtor, 'Rascunho');

        $this->concedidoA($disponivel, $this->consumidor);
        $this->concedidoA($rascunho, $this->consumidor);

        $resposta = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses');

        $resposta->assertOk();
        $this->assertSame([$disponivel->id()], array_column($resposta->json('data'), 'id'));
    }

    public function test_a_concessao_de_outro_consumidor_nao_vale_para_este(): void
    {
        $outro = User::factory()->consumer()->create();
        $curso = $this->cursoDisponivel('Do outro');
        $this->concedidoA($curso, $outro);

        $resposta = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses');

        $resposta->assertOk();
        $resposta->assertJsonCount(0, 'data');
    }

    public function test_consumidor_sem_nenhuma_concessao_recebe_lista_vazia(): void
    {
        $this->cursoDisponivel('Disponivel');

        // Lista vazia, e nao recusa: nao ter acesso a nada e um estado legitimo,
        // e transforma-lo em erro faria a interface tratar como falha a tela
        // inicial de quem ainda nao recebeu curso nenhum.
        $resposta = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses');

        $resposta->assertOk();
        $resposta->assertJsonCount(0, 'data');
        $resposta->assertJsonPath('meta.total', 0);
    }

    // -----------------------------------------------------------------------
    // Os numeros
    // -----------------------------------------------------------------------

    public function test_a_paginacao_conta_somente_o_conjunto_autorizado(): void
    {
        foreach (range(1, 3) as $i) {
            $this->concedidoA($this->cursoDisponivel('Concedido '.$i), $this->consumidor);
        }

        // Nao concedidos, e em quantidade suficiente para deslocar a contagem se
        // ela fosse feita antes do recorte.
        foreach (range(1, 12) as $i) {
            $this->cursoDisponivel('Alheio '.$i);
        }

        // Rascunho concedido: entra no recorte de concessao e sai no de estado.
        $this->concedidoA($this->umCurso($this->produtor, 'Rascunho'), $this->consumidor);

        $resposta = $this->actingAs($this->consumidor)
            ->getJson('/api/catalog/courses?per_page=2');

        $resposta->assertOk();
        $resposta->assertJsonCount(2, 'data');
        $resposta->assertJsonPath('meta.total', 3);
        $resposta->assertJsonPath('meta.last_page', 2);
        $resposta->assertJsonPath('meta.per_page', 2);
    }

    public function test_a_segunda_pagina_traz_o_restante_do_conjunto_autorizado(): void
    {
        $ids = [];

        foreach (range(1, 3) as $i) {
            $curso = $this->cursoDisponivel('Concedido '.$i);
            $this->concedidoA($curso, $this->consumidor);
            $ids[] = $curso->id();
        }

        $primeira = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses?per_page=2');
        $segunda = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses?per_page=2&page=2');

        $segunda->assertOk();
        $segunda->assertJsonCount(1, 'data');

        // Nenhum curso aparece nas duas paginas, e a uniao e o conjunto inteiro.
        $this->assertEqualsCanonicalizing(
            $ids,
            array_merge(
                array_column($primeira->json('data'), 'id'),
                array_column($segunda->json('data'), 'id'),
            ),
        );
    }

    public function test_a_resposta_usa_os_envelopes_existentes(): void
    {
        $this->concedidoA($this->cursoDisponivel('Concedido'), $this->consumidor);

        $resposta = $this->actingAs($this->consumidor)->getJson('/api/catalog/courses');

        $resposta->assertOk();
        $resposta->assertJsonStructure([
            'data' => [['id', 'title', 'description', 'owner_id', 'state', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            'links' => ['first', 'prev', 'next', 'last'],
        ]);

        $this->assertSame(
            ['current_page', 'per_page', 'total', 'last_page'],
            array_keys($resposta->json('meta')),
        );
    }

    // -----------------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------------

    /**
     * Um curso em `available`, promovido pelo proprio agregado.
     *
     * `makeAvailable()` em vez de um `update` na coluna: o estado e decidido pelo
     * dominio, e um cenario montado por fora existiria mesmo que a promocao
     * estivesse quebrada.
     */
    private function cursoDisponivel(string $titulo): Course
    {
        $curso = $this->umCurso($this->produtor, $titulo)->makeAvailable();

        $this->app->make(CourseRepository::class)->save($curso);

        return $curso;
    }
}
