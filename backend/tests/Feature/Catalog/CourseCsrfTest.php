<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A criacao de curso sob a protecao contra requisicao forjada.
 *
 * A T030 ja provou o mecanismo inteiro — cookie, cabecalho, fluxo HTTP real. O
 * que falta provar e especifico desta tarefa: que o endpoint novo **entrou** sob
 * essa protecao, em vez de ter sido registrado fora dela.
 *
 * A distincao importa porque a suite desvia o CSRF por padrao: sem ligar o ramo
 * de proposito, um `POST` desprotegido passaria em todos os outros testes deste
 * diretorio sem levantar suspeita.
 */
final class CourseCsrfTest extends TestCase
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

    public function test_o_desvio_de_ambiente_de_teste_esta_realmente_desligado(): void
    {
        // Sem esta afirmacao, o teste abaixo poderia passar pelo motivo errado.
        $this->assertTrue($this->app->runningUnitTests());

        $this->comCsrfAtivo();

        $this->assertFalse($this->app->runningUnitTests());
    }

    public function test_criacao_sem_token_responde_419_e_nao_cria_curso(): void
    {
        $produtor = User::factory()->producer()->create();
        $this->comCsrfAtivo();

        $resposta = $this->actingAs($produtor)->postJson('/api/courses', [
            'title' => 'Fundamentos de PHP',
            'description' => 'Descricao.',
        ]);

        $resposta->assertStatus(419);
        $resposta->assertHeader('content-type', 'application/problem+json');
        $resposta->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');

        // A recusa acontece antes do caso de uso: nada foi gravado.
        $this->assertDatabaseCount('courses', 0);
    }

    public function test_com_o_token_correto_a_criacao_acontece(): void
    {
        $produtor = User::factory()->producer()->create();

        // O cliente busca o cookie antes da operacao mutante; e dessa requisicao
        // que saem o token e a sessao que o guarda.
        $cookies = $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $token = null;
        foreach ($cookies->headers->getCookies() as $emitido) {
            if ($emitido->getName() === 'XSRF-TOKEN') {
                $token = $emitido->getValue();
            }
        }

        $this->assertNotNull($token, 'o cookie do token nao foi emitido');

        $this->comCsrfAtivo();

        // O valor enviado no cabecalho e o do **cookie**, e nao o token bruto da
        // sessao: o cookie e cifrado, e o middleware o decifra antes de comparar.
        $resposta = $this->actingAs($produtor)
            ->withHeader('X-XSRF-TOKEN', $token)
            ->postJson('/api/courses', [
                'title' => 'Fundamentos de PHP',
                'description' => 'Descricao.',
            ]);

        $resposta->assertCreated();
        $this->assertDatabaseCount('courses', 1);
    }
}
