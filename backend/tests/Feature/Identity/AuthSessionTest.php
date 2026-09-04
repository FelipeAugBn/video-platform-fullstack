<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Database\Seeders\EvaluationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A sessao, de ponta a ponta e contra o MySQL.
 *
 * O que estes testes exercem nao e a chamada isolada de cada rota: e o **ciclo**
 * — entrar, ser reconhecido, sair e deixar de ser reconhecido. Cada etapa
 * sozinha pode passar com a seguinte quebrada, e e a emenda entre elas que
 * sustenta a jornada da avaliacao.
 *
 * A sessao usada aqui e a de banco, e nao uma em memoria: sem isso nada
 * afirmaria que a coluna `user_id` em `CHAR(36)` comporta o UUID — o desencontro
 * que a T022 precisou corrigir na migration padrao.
 */
final class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_DEMO = 'VideoDemo2026!';

    /**
     * Toda requisicao destes testes sai com a origem do frontend.
     *
     * Nao e decoracao: o Sanctum so trata uma requisicao como first-party — e
     * portanto so liga sessao e CSRF nela — quando ela chega com `Origin` ou
     * `Referer` de um dominio declarado como stateful. Um teste sem esse
     * cabecalho exercitaria o caminho stateless, que nao e o que a aplicacao
     * usa, e a ausencia de sessao apareceria como defeito inexistente.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000');
    }

    /**
     * Devolve ao proximo pedido os cookies que a resposta emitiu.
     *
     * Sem isto, o ambiente de teste do framework nao se comporta como um
     * navegador: cada requisicao chega sem cookie, o middleware de sessao gera
     * um identificador novo, e o que mantem a autenticacao de pe entre uma
     * chamada e outra e a instancia guardada em memoria pelo container — nao o
     * cookie. Um ciclo que passasse assim nao provaria nada sobre a sessao.
     *
     * `forgetGuards` obriga a autenticacao a ser resolvida outra vez, a partir
     * do cookie devolvido, em vez de reaproveitar o usuario ja carregado.
     *
     * Ainda assim, a prova definitiva de troca de cookies e o fluxo HTTP
     * executado contra o servico web, em processos separados e sem container
     * compartilhado. Este helper aproxima o teste desse comportamento; nao o
     * substitui.
     */
    private function comCookiesDaResposta(TestResponse $resposta): TestResponse
    {
        foreach ($resposta->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === '') {
                continue;
            }

            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }

        $this->app['auth']->forgetGuards();

        return $resposta;
    }

    public function test_a_suite_usa_sessao_em_banco(): void
    {
        // Se esta afirmacao cair, todos os testes abaixo continuariam verdes
        // sem provar nada sobre a persistencia da sessao.
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('sessions', config('session.table'));
    }

    public function test_as_duas_contas_de_demonstracao_entram(): void
    {
        $this->seed(EvaluationSeeder::class);

        foreach ([
            'producer@video-platform.test' => 'producer',
            'consumer@video-platform.test' => 'consumer',
        ] as $email => $perfil) {
            $this->flushSession();

            $resposta = $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => self::SENHA_DEMO,
            ]);

            $resposta->assertOk()
                ->assertJsonPath('data.email', $email)
                ->assertJsonPath('data.role', $perfil);
        }
    }

    public function test_o_login_autentica_e_grava_a_sessao_no_banco(): void
    {
        $usuario = User::factory()->producer()->create(['password' => 'senha-valida']);

        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk();

        $this->assertAuthenticatedAs($usuario);

        // A linha existe e guarda o UUID inteiro. Uma coluna `BIGINT` — o padrao
        // do framework — nao comportaria este valor.
        $gravado = DB::table('sessions')->where('user_id', $usuario->id)->value('user_id');

        $this->assertSame($usuario->id, $gravado);
        $this->assertSame(36, strlen((string) $gravado));
    }

    public function test_o_identificador_da_sessao_muda_no_login(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        // Uma sessao de visitante, como a que o cliente obtem ao buscar o cookie
        // CSRF antes de qualquer operacao.
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $anterior = session()->getId();

        $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk();

        // Sem a regeneracao, um identificador plantado no navegador da vitima
        // antes do login continuaria valendo depois dele — e passaria a apontar
        // para a sessao autenticada.
        $this->assertNotSame($anterior, session()->getId());
    }

    public function test_o_ciclo_completo_entra_e_deixa_de_ser_reconhecido(): void
    {
        $usuario = User::factory()->consumer()->create(['password' => 'senha-valida']);

        $this->comCookiesDaResposta($this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk());

        $this->comCookiesDaResposta(
            $this->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('data.id', $usuario->id)
                ->assertJsonPath('data.role', 'consumer')
        );

        $this->comCookiesDaResposta($this->postJson('/api/auth/logout')->assertNoContent());

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_o_cookie_anterior_nao_reabre_a_sessao_apos_o_logout(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        $login = $this->comCookiesDaResposta($this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk());

        // Guardado antes do logout, e reapresentado depois dele.
        $cookieAutenticado = collect($login->headers->getCookies())
            ->firstWhere(fn ($cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookieAutenticado, 'o login precisa emitir o cookie de sessao');

        $this->comCookiesDaResposta($this->postJson('/api/auth/logout')->assertNoContent());

        $this->app['auth']->forgetGuards();

        // A prova e comportamental, e nao a contagem de linhas: o mesmo cookie
        // que valia antes deixa de abrir a sessao.
        $this->withUnencryptedCookie($cookieAutenticado->getName(), $cookieAutenticado->getValue())
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_me_sem_sessao_responde_401_no_formato_do_contrato(): void
    {
        $resposta = $this->getJson('/api/auth/me');

        $resposta->assertUnauthorized()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonPath('status', 401);
    }

    public function test_logout_sem_sessao_responde_401(): void
    {
        $this->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_as_respostas_nao_carregam_senha_nem_dado_de_sessao(): void
    {
        $usuario = User::factory()->create(['password' => 'senha-valida']);

        $login = $this->postJson('/api/auth/login', [
            'email' => $usuario->email,
            'password' => 'senha-valida',
        ])->assertOk();

        $me = $this->getJson('/api/auth/me')->assertOk();

        foreach ([$login, $me] as $resposta) {
            $corpo = $resposta->json('data');

            $this->assertSame(['id', 'name', 'email', 'role'], array_keys($corpo));

            $texto = $resposta->getContent();
            foreach (['password', 'senha-valida', 'remember_token', 'session', 'token'] as $proibido) {
                $this->assertStringNotContainsStringIgnoringCase($proibido, (string) $texto);
            }
        }
    }
}
