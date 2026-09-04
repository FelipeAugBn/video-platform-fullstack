<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A protecao contra requisicao forjada por outro site.
 *
 * **Um teste ingenuo aqui nao prova nada.** O middleware do framework tem uma
 * saida logo na primeira linha: quando a aplicacao esta em ambiente de teste,
 * ele libera a requisicao sem olhar o token. Um teste que enviasse um POST sem
 * token e recebesse sucesso estaria comprovando o desvio, e nao a protecao.
 *
 * Por isso cada teste deste arquivo troca o ambiente por `local` antes da
 * requisicao. E a unica condicao do desvio que depende de configuracao, e
 * altera-la coloca o middleware no mesmo caminho que ele percorre em execucao.
 *
 * A conferencia complementar acontece fora da suite, por HTTP real contra o
 * servico web — ali nao ha ambiente de teste nem container compartilhado.
 */
final class AuthCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
    }

    /**
     * Desliga o desvio de ambiente de teste do middleware de CSRF.
     */
    private function comCsrfAtivo(): void
    {
        $this->app->instance('env', 'local');
    }

    public function test_o_desvio_de_ambiente_de_teste_esta_realmente_desligado(): void
    {
        // Sem esta afirmacao, todos os testes abaixo poderiam estar passando
        // pelo motivo errado.
        $this->assertTrue($this->app->runningUnitTests(), 'o ambiente padrao da suite desvia o CSRF');

        $this->comCsrfAtivo();

        $this->assertFalse($this->app->runningUnitTests(), 'o desvio continua ativo apos a troca');
    }

    public function test_requisicao_mutante_sem_token_responde_419(): void
    {
        $this->comCsrfAtivo();

        $resposta = $this->postJson('/api/auth/login', [
            'email' => 'alguem@video-platform.test',
            'password' => 'qualquer-senha',
        ]);

        $resposta->assertStatus(419)
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH')
            ->assertJsonPath('status', 419)
            ->assertJsonPath('title', 'Token de seguranca invalido');
    }

    public function test_token_invalido_responde_419(): void
    {
        $this->comCsrfAtivo();

        $this->withHeader('X-XSRF-TOKEN', 'token-que-nao-confere')
            ->postJson('/api/auth/login', [
                'email' => 'alguem@video-platform.test',
                'password' => 'qualquer-senha',
            ])
            ->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_a_operacao_nao_acontece_quando_o_csrf_falha(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        $this->comCsrfAtivo();

        // Credencial correta, token ausente: a requisicao morre antes de o
        // controller existir.
        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertStatus(419);

        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->whereNotNull('user_id')->count());
    }

    public function test_a_resposta_de_419_nao_expoe_detalhe_interno(): void
    {
        $this->comCsrfAtivo();

        $corpo = (string) $this->postJson('/api/auth/login', [
            'email' => 'alguem@video-platform.test',
            'password' => 'qualquer-senha',
        ])->getContent();

        foreach (['TokenMismatch', 'PreventRequestForgery', '/app/', 'vendor', 'Exception'] as $vazamento) {
            $this->assertStringNotContainsString($vazamento, $corpo);
        }
    }

    public function test_com_o_token_correto_a_operacao_acontece(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        // O cliente busca o cookie antes de qualquer operacao mutante; e dessa
        // requisicao que saem o token e a sessao que o guarda.
        $cookie = $this->get('/sanctum/csrf-cookie')->assertNoContent();

        // Os cookies precisam voltar na requisicao seguinte, como o navegador
        // faria: o token so confere contra a sessao que o emitiu, e sem o cookie
        // de sessao a requisicao chegaria com uma sessao nova e outro token.
        $token = null;

        foreach ($cookie->headers->getCookies() as $emitido) {
            if ($emitido->getValue() === '') {
                continue;
            }

            $this->withUnencryptedCookie($emitido->getName(), $emitido->getValue());

            // O valor que segue no cabecalho e o do proprio cookie `XSRF-TOKEN`,
            // e nao o token em claro: e o cookie que o JavaScript le e devolve,
            // e e ele que o servidor decifra do outro lado.
            if ($emitido->getName() === 'XSRF-TOKEN') {
                $token = $emitido->getValue();
            }
        }

        $this->assertNotNull($token, 'o endpoint precisa emitir o cookie XSRF-TOKEN');

        $this->comCsrfAtivo();

        $this->withHeader('X-XSRF-TOKEN', $token)
            ->postJson('/api/auth/login', [
                'email' => $usuario->email,
                'password' => 'senha-valida',
            ])
            ->assertOk()
            ->assertJsonPath('data.email', $usuario->email);

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_leitura_nao_exige_token(): void
    {
        $this->comCsrfAtivo();

        // `GET` nao muda estado e nao e o vetor do CSRF; exigir token nele
        // quebraria toda navegacao sem proteger nada.
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
