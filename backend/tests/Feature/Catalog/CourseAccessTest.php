<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Quem alcanca as rotas de curso — e quem nao alcanca.
 *
 * Os dois middlewares sao exercidos nas **tres** rotas, e nao apenas na criacao.
 * Uma rota que nascesse fora do grupo continuaria funcionando nos testes de
 * comportamento: ela responderia certo para o produtor autenticado, e so estaria
 * aberta para todo o resto.
 *
 * A ordem das duas negativas tambem e verificada. `401` antes de `403` importa:
 * invertida, um visitante anonimo receberia `403` e aprenderia que existe ali
 * algo reservado a um perfil especifico.
 */
final class CourseAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rotasDeCurso(): iterable
    {
        $id = '01936f1a-7c00-7000-8000-0000000000aa';

        yield 'criar' => ['post', '/api/courses'];
        yield 'listar' => ['get', '/api/courses'];
        yield 'detalhar' => ['get', '/api/courses/'.$id];
    }

    #[DataProvider('rotasDeCurso')]
    public function test_sem_sessao_a_rota_responde_401(string $metodo, string $caminho): void
    {
        $resposta = $this->{$metodo.'Json'}($caminho, []);

        $resposta->assertStatus(401);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('rotasDeCurso')]
    public function test_consumidor_autenticado_recebe_403(string $metodo, string $caminho): void
    {
        $consumidor = User::factory()->consumer()->create();

        $resposta = $this->actingAs($consumidor)->{$metodo.'Json'}($caminho, []);

        $resposta->assertStatus(403);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'FORBIDDEN');
    }

    #[DataProvider('rotasDeCurso')]
    public function test_produtor_autenticado_passa_pelos_dois_middlewares(string $metodo, string $caminho): void
    {
        // O par positivo: sem ele, um grupo que recusasse todo mundo passaria nos
        // dois testes acima.
        $produtor = User::factory()->producer()->create();

        $resposta = $this->actingAs($produtor)->{$metodo.'Json'}($caminho, []);

        $this->assertNotContains(
            $resposta->getStatusCode(),
            [401, 403],
            "a rota {$caminho} recusou um produtor autenticado",
        );
    }

    public function test_o_perfil_errado_e_recusado_antes_de_o_recurso_ser_procurado(): void
    {
        $consumidor = User::factory()->consumer()->create();
        $inexistente = (string) Uuid::uuid7();

        // Perfil incorreto responde `403` mesmo para um identificador que nao
        // existe. E a distincao central desta tarefa: `403` fala sobre **quem
        // pede**, e nao revela nada sobre o recurso; `404` fala sobre o recurso,
        // e por isso e usado tanto para ausente quanto para alheio.
        $this->actingAs($consumidor)
            ->getJson('/api/courses/'.$inexistente)
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_visitante_anonimo_recebe_401_e_nao_403(): void
    {
        $this->getJson('/api/courses/'.(string) Uuid::uuid7())
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
