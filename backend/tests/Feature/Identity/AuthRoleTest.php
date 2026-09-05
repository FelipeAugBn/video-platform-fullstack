<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A primeira camada de autorizacao: o perfil na fronteira HTTP.
 *
 * As rotas exercitadas aqui sao **registradas pelo proprio teste** e nao existem
 * na aplicacao em execucao. Endpoint criado so para ser testado e superficie que
 * ninguem pediu, e que alguem acabaria chamando.
 *
 * O que este middleware **nao** faz e tao importante quanto o que ele faz: ele
 * responde "que tipo de usuario pode chamar esta rota", nunca "este recurso e
 * seu". A segunda pergunta e de propriedade e chega com os casos de uso do
 * catalogo (plan §9.3) — passar por aqui nao concede acesso a recurso nenhum.
 */
final class AuthRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');

        Route::middleware(['api', 'auth:sanctum', 'role:producer'])
            ->get('/api/_teste/area-do-produtor', fn (): array => ['data' => ['area' => 'produtor']]);

        Route::middleware(['api', 'auth:sanctum', 'role:consumer'])
            ->get('/api/_teste/area-do-consumidor', fn (): array => ['data' => ['area' => 'consumidor']]);
    }

    public function test_sem_sessao_a_rota_de_perfil_responde_401(): void
    {
        $this->getJson('/api/_teste/area-do-produtor')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_perfil_incorreto_responde_403(): void
    {
        $consumidor = User::factory()->consumer()->create(['password' => 'senha-valida']);

        $this->postJson('/api/auth/login', [
            'email' => $consumidor->email,
            'password' => 'senha-valida',
        ])->assertOk();

        // Autenticado, porem sem o perfil da rota. Nao ha saida pela mesma
        // sessao — e por isso a resposta e diferente do `401`, que reconduz ao
        // login (RF-UI-013, RF-UI-014).
        $this->getJson('/api/_teste/area-do-produtor')
            ->assertForbidden()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('status', 403);
    }

    public function test_cada_perfil_alcanca_a_propria_area(): void
    {
        $produtor = User::factory()->producer()->create(['password' => 'senha-valida']);

        $this->postJson('/api/auth/login', [
            'email' => $produtor->email,
            'password' => 'senha-valida',
        ])->assertOk();

        $this->getJson('/api/_teste/area-do-produtor')
            ->assertOk()
            ->assertJsonPath('data.area', 'produtor');

        $this->getJson('/api/_teste/area-do-consumidor')->assertForbidden();

        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $consumidor = User::factory()->consumer()->create(['password' => 'senha-valida']);

        $this->postJson('/api/auth/login', [
            'email' => $consumidor->email,
            'password' => 'senha-valida',
        ])->assertOk();

        $this->getJson('/api/_teste/area-do-consumidor')
            ->assertOk()
            ->assertJsonPath('data.area', 'consumidor');

        $this->getJson('/api/_teste/area-do-produtor')->assertForbidden();
    }

    public function test_a_negativa_por_perfil_nao_revela_detalhe_interno(): void
    {
        $consumidor = User::factory()->consumer()->create(['password' => 'senha-valida']);

        $this->postJson('/api/auth/login', [
            'email' => $consumidor->email,
            'password' => 'senha-valida',
        ])->assertOk();

        $corpo = (string) $this->getJson('/api/_teste/area-do-produtor')->getContent();

        foreach (['EnsureUserHasRole', 'middleware', 'Exception', '/app/', 'producer'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    public function test_as_rotas_de_teste_nao_existem_na_aplicacao(): void
    {
        // O teste registra as proprias rotas; o arquivo de rotas de producao
        // continua com apenas as de autenticacao e as do catalogo do produtor —
        // curso, modulo, aula e estrutura.
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($rota): string => (string) $rota->uri())
            ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/'))
            // Um mesmo endereco pode ser servido por mais de um metodo — a
            // colecao de cursos atende `GET` e `POST`. A afirmacao e sobre quais
            // enderecos existem, e nao sobre quantas vezes cada um aparece.
            ->unique()
            ->values();

        $this->assertEqualsCanonicalizing(
            [
                'api/auth/login', 'api/auth/me', 'api/auth/logout',
                'api/courses', 'api/courses/{course}',
                'api/courses/{course}/modules', 'api/courses/{course}/structure',
                'api/modules/{module}/lessons', 'api/lessons/{lesson}',
                'api/_teste/area-do-produtor', 'api/_teste/area-do-consumidor',
            ],
            $rotas->all(),
        );
    }
}
