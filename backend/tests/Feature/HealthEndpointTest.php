<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O endpoint de saude e o unico ponto HTTP que existe antes das rotas da API.
 *
 * Ele e o sinal HTTP temporario usado pelo healthcheck do servico `web`
 * enquanto `/api/health` ainda nao existe. Um teste aqui protege esse sinal —
 * se a rota deixar de responder, a falha aparece na suite, e nao como um
 * container preso em `starting` sem explicacao.
 */
final class HealthEndpointTest extends TestCase
{
    public function test_endpoint_de_saude_responde_com_sucesso(): void
    {
        $resposta = $this->get('/up');

        $resposta->assertOk();
    }

    /**
     * A raiz saiu junto com o frontend demonstrativo do scaffold. Este backend
     * serve apenas a API, e uma pagina de boas-vindas respondendo em `/` seria
     * sinal de que o material de demonstracao voltou.
     */
    public function test_a_raiz_nao_responde(): void
    {
        $resposta = $this->get('/');

        $resposta->assertNotFound();
    }
}
