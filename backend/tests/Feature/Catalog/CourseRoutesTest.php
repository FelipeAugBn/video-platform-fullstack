<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RotaRegistrada;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * A superficie exposta pelo catalogo — e o que ela deliberadamente nao tem.
 *
 * O teste que mais importa aqui e o negativo. Atualizar e excluir curso nao fazem
 * parte desta entrega, e uma rota declarada sem caso de uso e superficie exposta
 * com comportamento indefinido. A lista fechada faz uma rota nova falhar em vez
 * de passar despercebida por ter sido copiada de outra tarefa.
 */
final class CourseRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A origem declarada e o que faz o Sanctum tratar a requisicao como
        // vinda do frontend: sem ela, a sessao e a protecao contra requisicao
        // forjada nao sao aplicadas, e o teste passaria a exercitar um caminho
        // que nao existe em execucao (mesma convencao dos testes da T030).
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_as_rotas_de_autenticacao_continuam_funcionando(): void
    {
        $usuario = User::factory()->producer()->create(['password' => 'senha-valida']);

        // O catalogo entrou sem deslocar o que ja existia: o mesmo login de antes
        // continua respondendo no mesmo endereco.
        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk()->assertJsonPath('data.role', 'producer');

        $this->actingAs($usuario)->getJson('/api/auth/me')->assertOk();
        $this->actingAs($usuario)->postJson('/api/auth/logout')->assertNoContent();
    }

    #[DataProvider('metodosNaoPrevistos')]
    public function test_nao_existe_rota_de_atualizacao_nem_de_exclusao(string $metodo): void
    {
        $produtor = User::factory()->producer()->create();

        // Autenticado e com o perfil certo: a recusa precisa vir de a rota nao
        // existir, e nao de faltar permissao. Sem o `actingAs`, um `401` daria a
        // impressao de que a rota existe e apenas exige sessao.
        $resposta = $this->actingAs($produtor)
            ->json($metodo, '/api/courses/'.(string) Uuid::uuid7());

        $this->assertContains(
            $resposta->getStatusCode(),
            [404, 405],
            "o metodo {$metodo} nao deveria ser atendido",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function metodosNaoPrevistos(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    public function test_a_colecao_tambem_nao_aceita_verbos_alem_de_get_e_post(): void
    {
        $produtor = User::factory()->producer()->create();

        foreach (['PUT', 'PATCH', 'DELETE'] as $metodo) {
            $resposta = $this->actingAs($produtor)->json($metodo, '/api/courses');

            $this->assertContains($resposta->getStatusCode(), [404, 405], $metodo);
        }
    }

    public function test_as_tres_rotas_de_curso_exigem_sessao_e_perfil_de_produtor(): void
    {
        $doCatalogo = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RotaRegistrada $rota): bool => str_starts_with((string) $rota->uri(), 'api/courses'));

        $this->assertCount(3, $doCatalogo);

        // A verificacao e na declaracao da rota, e nao apenas no comportamento:
        // uma rota que respondesse certo hoje por outro motivo — um middleware
        // global, por exemplo — deixaria de responder ao ser movida.
        foreach ($doCatalogo as $rota) {
            $middlewares = $rota->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middlewares, (string) $rota->uri());
            $this->assertContains('role:producer', $middlewares, (string) $rota->uri());
        }
    }

    public function test_a_rota_de_detalhe_restringe_o_parametro_ao_formato_uuid(): void
    {
        $rota = collect(Route::getRoutes()->getRoutes())
            ->first(fn (RotaRegistrada $r): bool => (string) $r->uri() === 'api/courses/{course}');

        $this->assertNotNull($rota);
        $this->assertArrayHasKey('course', $rota->wheres);
    }
}
