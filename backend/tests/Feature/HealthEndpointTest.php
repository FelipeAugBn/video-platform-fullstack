<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O endpoint de saude e a verificacao HTTP definitiva deste ambiente.
 *
 * E ele que o healthcheck do servico `web` consulta, e nao ha outra rota
 * prevista para esse papel: uma segunda verificacao de prontidao seria um
 * segundo lugar afirmando a mesma coisa, livre para divergir. Fica fora do
 * prefixo da API de proposito — prova que a aplicacao responde, e nada a
 * respeito das dependencias dela (plan §16.2).
 *
 * Um teste aqui protege esse sinal: se a rota deixar de responder, a falha
 * aparece na suite, e nao como um container preso em `starting` sem explicacao.
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
