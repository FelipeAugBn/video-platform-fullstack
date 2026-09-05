<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\Domain\Course;
use App\Catalog\Domain\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CatalogoDeTeste;
use Tests\TestCase;

/**
 * As duas operacoes de escrita desta tarefa sob a protecao contra requisicao
 * forjada.
 *
 * A T030 ja provou o mecanismo inteiro — cookie, cabecalho, fluxo HTTP real. O
 * que falta provar e especifico daqui: que os endpoints novos **entraram** sob
 * essa protecao, em vez de terem sido registrados fora dela.
 *
 * A distincao importa porque a suite desvia o CSRF por padrao: sem ligar o ramo
 * de proposito, um `POST` desprotegido passaria em todos os outros testes deste
 * diretorio sem levantar suspeita.
 */
final class CatalogCsrfTest extends TestCase
{
    use CatalogoDeTeste;
    use RefreshDatabase;

    private User $produtor;

    private Course $curso;

    private Module $modulo;

    protected function setUp(): void
    {
        parent::setUp();

        // A origem declarada e o que faz o Sanctum tratar a requisicao como
        // vinda do frontend: sem ela, a sessao e a protecao contra requisicao
        // forjada nao sao aplicadas, e o teste passaria a exercitar um caminho
        // que nao existe em execucao (mesma convencao dos testes da T030).
        $this->withHeader('Origin', 'http://localhost:3000');

        $this->produtor = User::factory()->producer()->create();
        $this->curso = $this->umCurso($this->produtor);
        $this->modulo = $this->umModulo($this->curso);
    }

    /**
     * Liga o ramo real da protecao.
     *
     * O middleware desvia quando o processo esta rodando a suite. Trocar o
     * ambiente registrado no container faz a condicao deixar de valer, e o codigo
     * que roda em producao passa a ser exercido.
     */
    private function comCsrfAtivo(): void
    {
        $this->app->instance('env', 'local');
    }

    public function test_criar_modulo_sem_token_responde_419_e_nao_grava(): void
    {
        $this->comCsrfAtivo();

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/courses/{$this->curso->id()}/modules", ['title' => 'Introducao']);

        $resposta->assertStatus(419);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');

        // A recusa acontece antes do caso de uso: so existe o modulo do cenario.
        $this->assertDatabaseCount('modules', 1);
    }

    public function test_criar_aula_sem_token_responde_419_e_nao_grava(): void
    {
        $this->comCsrfAtivo();

        $resposta = $this->actingAs($this->produtor)
            ->postJson("/api/modules/{$this->modulo->id()}/lessons", ['title' => 'Primeiros passos']);

        $resposta->assertStatus(419);
        $resposta->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_com_o_token_correto_as_duas_criacoes_acontecem(): void
    {
        // O par positivo: sem ele, uma protecao que recusasse tudo passaria nos
        // dois testes acima.
        $token = $this->tokenDeProtecao();

        $this->comCsrfAtivo();

        $this->actingAs($this->produtor)
            ->withHeader('X-XSRF-TOKEN', $token)
            ->postJson("/api/courses/{$this->curso->id()}/modules", ['title' => 'Introducao'])
            ->assertCreated();

        $this->actingAs($this->produtor)
            ->withHeader('X-XSRF-TOKEN', $token)
            ->postJson("/api/modules/{$this->modulo->id()}/lessons", ['title' => 'Primeiros passos'])
            ->assertCreated();

        $this->assertDatabaseCount('modules', 2);
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_as_leituras_nao_exigem_token(): void
    {
        // A protecao existe para operacoes que alteram estado. Exigi-la numa
        // leitura tornaria a consulta impossivel sem um passo previo, sem
        // proteger nada.
        $this->comCsrfAtivo();

        $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$this->curso->id()}/modules")
            ->assertOk();

        $this->actingAs($this->produtor)
            ->getJson("/api/courses/{$this->curso->id()}/structure")
            ->assertOk();
    }

    /**
     * O valor do cookie que o cliente devolve no cabecalho.
     *
     * E o do **cookie**, e nao o token bruto da sessao: o cookie e cifrado, e o
     * middleware o decifra antes de comparar.
     */
    private function tokenDeProtecao(): string
    {
        $cookies = $this->get('/sanctum/csrf-cookie')->assertNoContent();

        foreach ($cookies->headers->getCookies() as $emitido) {
            if ($emitido->getName() === 'XSRF-TOKEN') {
                return $emitido->getValue();
            }
        }

        $this->fail('o cookie do token nao foi emitido');
    }
}
