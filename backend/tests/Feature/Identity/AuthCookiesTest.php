<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * Os dois cookies, e o atributo que separa um do outro.
 *
 * A confusao que este arquivo existe para impedir: **nao e o CORS que decide se
 * o JavaScript le um cookie.** `Access-Control-Expose-Headers` governa
 * cabecalhos de resposta, e `Set-Cookie` nunca fica acessivel por ele. Quem
 * decide e o atributo `HttpOnly` do proprio cookie.
 *
 * Dai a divisao:
 *
 *   `XSRF-TOKEN`      sem `HttpOnly` — precisa ser lido, para voltar no
 *                     cabecalho `X-XSRF-TOKEN`. Sozinho nao autentica ninguem.
 *   cookie de sessao  com `HttpOnly` — e a credencial, e por isso fica fora do
 *                     alcance de qualquer script da pagina.
 *
 * E essa separacao que faz a protecao funcionar: um site de terceiro consegue
 * fazer o navegador enviar o cookie de sessao, mas nao consegue ler o token para
 * reenvia-lo junto.
 */
final class AuthCookiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_o_endpoint_de_token_emite_os_dois_cookies(): void
    {
        $resposta = $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $this->assertNotNull($this->cookie($resposta->headers->getCookies(), 'XSRF-TOKEN'));
        $this->assertNotNull($this->cookie($resposta->headers->getCookies(), config('session.cookie')));
    }

    public function test_o_cookie_do_token_e_legivel_pelo_javascript(): void
    {
        $resposta = $this->get('/sanctum/csrf-cookie');

        $token = $this->cookie($resposta->headers->getCookies(), 'XSRF-TOKEN');

        $this->assertNotNull($token);
        $this->assertFalse($token->isHttpOnly(), 'o cliente precisa ler este cookie para devolve-lo no cabecalho');
    }

    public function test_o_cookie_de_sessao_e_inacessivel_ao_javascript(): void
    {
        $resposta = $this->get('/sanctum/csrf-cookie');

        $sessao = $this->cookie($resposta->headers->getCookies(), config('session.cookie'));

        $this->assertNotNull($sessao);
        $this->assertTrue($sessao->isHttpOnly(), 'a credencial nao pode ficar ao alcance de um script');
    }

    public function test_os_dois_cookies_sao_host_only_e_lax_no_ambiente_local(): void
    {
        $resposta = $this->get('/sanctum/csrf-cookie');

        foreach (['XSRF-TOKEN', config('session.cookie')] as $nome) {
            $cookie = $this->cookie($resposta->headers->getCookies(), $nome);

            $this->assertNotNull($cookie);

            // Sem dominio declarado: o navegador devolve o cookie apenas ao host
            // que o emitiu. Cookies nao sao separados por porta, e por isso o
            // mesmo cookie de `localhost` serve as portas 3000 e 8080.
            $this->assertNull($cookie->getDomain(), "{$nome} deveria ser host-only");
            $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite(), "{$nome} deveria ser lax");

            // HTTP local. Sob HTTPS o ambiente marca `Secure`, e a configuracao
            // vem do ambiente justamente para permitir isso.
            $this->assertFalse($cookie->isSecure(), "{$nome} nao deveria exigir HTTPS no ambiente local");
        }
    }

    public function test_o_fluxo_com_o_cabecalho_do_token_continua_funcionando(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        $resposta = $this->get('/sanctum/csrf-cookie');
        $token = null;

        foreach ($resposta->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === '') {
                continue;
            }

            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());

            if ($cookie->getName() === 'XSRF-TOKEN') {
                $token = $cookie->getValue();
            }
        }

        // Com a protecao de CSRF ativa, e nao no desvio de ambiente de teste.
        $this->app->instance('env', 'local');

        $this->withHeader('X-XSRF-TOKEN', (string) $token)
            ->postJson('/api/auth/login', [
                'email' => $usuario->email,
                'password' => 'senha-valida',
            ])
            ->assertOk();

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_a_configuracao_da_sessao_vem_do_ambiente(): void
    {
        // Os valores conferidos aqui sao os do ambiente **local**. Eles nao sao
        // fixos no codigo: vem do ambiente, e uma publicacao sob HTTPS troca
        // `secure` por verdadeiro sem tocar em arquivo versionado. O teste
        // afirma a configuracao desta maquina, e nao proibe a outra.
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('sessions', config('session.table'));
        $this->assertNull(config('session.domain'), 'cookie host-only no ambiente local');
        $this->assertFalse(config('session.secure'), 'HTTP local; sob HTTPS este valor passa a verdadeiro');
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    /**
     * @param  list<Cookie>  $cookies
     */
    private function cookie(array $cookies, string $nome): ?Cookie
    {
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === $nome) {
                return $cookie;
            }
        }

        return null;
    }
}
