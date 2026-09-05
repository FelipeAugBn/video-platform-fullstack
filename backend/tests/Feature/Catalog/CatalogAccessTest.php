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
 * Quem alcanca as rotas de modulo, aula e estrutura — e quem nao alcanca.
 *
 * Os dois middlewares sao exercidos nas **cinco** rotas novas, e nao apenas nas
 * de escrita. Uma rota que nascesse fora do grupo continuaria funcionando nos
 * testes de comportamento: ela responderia certo para o produtor autenticado, e
 * so estaria aberta para todo o resto.
 *
 * A ordem das duas negativas tambem e verificada. `401` antes de `403` importa:
 * invertida, um visitante anonimo receberia `403` e aprenderia que existe ali
 * algo reservado a um perfil especifico.
 */
final class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    private const CURSO = '01936f1a-7c00-7000-8000-0000000000aa';

    private const MODULO = '01936f1a-7c00-7000-8000-0000000000bb';

    private const AULA = '01936f1a-7c00-7000-8000-0000000000cc';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rotasNovas(): iterable
    {
        yield 'criar modulo' => ['post', '/api/courses/'.self::CURSO.'/modules'];
        yield 'listar modulos' => ['get', '/api/courses/'.self::CURSO.'/modules'];
        yield 'estrutura' => ['get', '/api/courses/'.self::CURSO.'/structure'];
        yield 'criar aula' => ['post', '/api/modules/'.self::MODULO.'/lessons'];
        yield 'consultar aula' => ['get', '/api/lessons/'.self::AULA];
    }

    // -----------------------------------------------------------------------
    // Sem sessao
    // -----------------------------------------------------------------------

    #[DataProvider('rotasNovas')]
    public function test_sem_sessao_a_rota_responde_401(string $metodo, string $caminho): void
    {
        $resposta = $this->{$metodo.'Json'}($caminho, []);

        $resposta->assertStatus(401);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('rotasNovas')]
    public function test_o_401_nao_depende_do_cabecalho_accept(string $metodo, string $caminho): void
    {
        // O contrato da API nao pode depender de um cabecalho que quem chama
        // controla. Sem `Accept`, o middleware de autenticacao do framework
        // tentaria montar um redirecionamento para uma rota `login` que nao
        // existe — e a resposta viraria `500`. O tratamento registrado no
        // bootstrap fecha isso, e este teste o mantem fechado nas rotas novas.
        $resposta = $this->call(strtoupper($metodo), $caminho);

        $this->assertSame(401, $resposta->getStatusCode());
        $this->assertSame('application/problem+json', $resposta->headers->get('content-type'));
        $this->assertNull($resposta->headers->get('Location'));
    }

    // -----------------------------------------------------------------------
    // Perfil errado
    // -----------------------------------------------------------------------

    #[DataProvider('rotasNovas')]
    public function test_consumidor_autenticado_recebe_403(string $metodo, string $caminho): void
    {
        $consumidor = User::factory()->consumer()->create();

        $resposta = $this->actingAs($consumidor)->{$metodo.'Json'}($caminho, []);

        $resposta->assertStatus(403);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'FORBIDDEN');
    }

    #[DataProvider('rotasNovas')]
    public function test_produtor_autenticado_passa_pelos_dois_middlewares(string $metodo, string $caminho): void
    {
        // O par positivo: sem ele, um grupo que recusasse todo mundo passaria nos
        // dois testes acima. O recurso nao existe, entao a resposta esperada e
        // `404` ou `422` — nunca `401` nem `403`.
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
        // `403` fala sobre **quem pede**, e nao revela nada sobre o recurso;
        // `404` fala sobre o recurso, e por isso e usado tanto para ausente
        // quanto para alheio. Um consumidor recebe `403` mesmo para um
        // identificador que nunca existiu.
        $consumidor = User::factory()->consumer()->create();

        $this->actingAs($consumidor)
            ->getJson('/api/lessons/'.(string) Uuid::uuid7())
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_visitante_anonimo_recebe_401_e_nao_403(): void
    {
        $this->getJson('/api/courses/'.(string) Uuid::uuid7().'/structure')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    // -----------------------------------------------------------------------
    // A superficie exposta
    // -----------------------------------------------------------------------

    public function test_as_cinco_rotas_novas_exigem_sessao_e_perfil_de_produtor(): void
    {
        $novas = [
            'api/courses/{course}/modules',
            'api/courses/{course}/structure',
            'api/modules/{module}/lessons',
            'api/lessons/{lesson}',
        ];

        $registradas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RotaRegistrada $rota): bool => in_array((string) $rota->uri(), $novas, true));

        // Quatro enderecos, cinco rotas: a colecao de modulos atende `GET` e
        // `POST` no mesmo endereco.
        $this->assertCount(5, $registradas);

        // A verificacao e na declaracao da rota, e nao apenas no comportamento:
        // uma rota que respondesse certo hoje por outro motivo — um middleware
        // global, por exemplo — deixaria de responder ao ser movida.
        foreach ($registradas as $rota) {
            $middlewares = $rota->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middlewares, (string) $rota->uri());
            $this->assertContains('role:producer', $middlewares, (string) $rota->uri());
        }
    }

    public function test_todo_parametro_de_rota_e_restrito_ao_formato_uuid(): void
    {
        $parametros = [
            'api/courses/{course}/modules' => 'course',
            'api/courses/{course}/structure' => 'course',
            'api/modules/{module}/lessons' => 'module',
            'api/lessons/{lesson}' => 'lesson',
        ];

        foreach ($parametros as $uri => $parametro) {
            $rota = collect(Route::getRoutes()->getRoutes())
                ->first(fn (RotaRegistrada $r): bool => (string) $r->uri() === $uri);

            $this->assertNotNull($rota, $uri);
            $this->assertArrayHasKey($parametro, $rota->wheres, $uri);
        }
    }

    #[DataProvider('enderecosSemEscrita')]
    public function test_nao_existe_atualizacao_nem_exclusao(string $metodo, string $caminho): void
    {
        // Autenticado e com o perfil certo: a recusa precisa vir de a rota nao
        // existir, e nao de faltar permissao. Sem o `actingAs`, um `401` daria a
        // impressao de que a rota existe e apenas exige sessao.
        $produtor = User::factory()->producer()->create();

        $resposta = $this->actingAs($produtor)->json($metodo, $caminho);

        $this->assertContains(
            $resposta->getStatusCode(),
            [404, 405],
            "o metodo {$metodo} nao deveria ser atendido em {$caminho}",
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function enderecosSemEscrita(): iterable
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $metodo) {
            yield "{$metodo} modulo" => [$metodo, '/api/courses/'.self::CURSO.'/modules'];
            yield "{$metodo} aula" => [$metodo, '/api/lessons/'.self::AULA];
        }
    }

    public function test_nao_existe_listagem_separada_de_aulas_do_modulo(): void
    {
        // As aulas ja aparecem na estrutura e sao consultaveis individualmente.
        // Uma terceira forma de ler a mesma lista seria superficie a mais com o
        // mesmo conteudo — e mais um lugar onde o filtro por dono precisaria estar
        // certo.
        $produtor = User::factory()->producer()->create();

        $resposta = $this->actingAs($produtor)
            ->getJson('/api/modules/'.self::MODULO.'/lessons');

        $this->assertContains($resposta->getStatusCode(), [404, 405]);
    }

    public function test_nao_existe_rota_de_reordenacao(): void
    {
        // Reordenacao e escopo opcional pelo proprio desafio (RF-MOD-005), e nao
        // faz parte desta entrega.
        $comReordenacao = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RotaRegistrada $rota): string => (string) $rota->uri())
            ->filter(fn (string $uri): bool => str_contains($uri, 'reorder')
                || str_contains($uri, 'position')
                || str_contains($uri, 'ordem'));

        $this->assertCount(0, $comReordenacao);
    }
}
