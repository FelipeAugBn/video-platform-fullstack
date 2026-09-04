<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Tests\TestCase;

/**
 * A negociacao que o navegador faz antes de deixar a pagina ler a resposta.
 *
 * Duas propriedades sao verificadas juntas porque uma sem a outra nao serve:
 * a origem precisa voltar **exata** — nunca `*` — e as credenciais precisam
 * estar autorizadas. Com credenciais habilitadas o proprio navegador recusa o
 * curinga, e sem credenciais o cookie de sessao nao viaja e a autenticacao
 * nunca acontece (plan §9.2).
 */
final class AuthCorsTest extends TestCase
{
    private const PERMITIDA = 'http://localhost:3000';

    public function test_o_preflight_da_origem_permitida_autoriza_com_credenciais(): void
    {
        $resposta = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::PERMITIDA,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ]);

        $resposta->assertSuccessful();

        // A origem volta exata. Um `*` aqui invalidaria o envio de credenciais
        // e, pior, abriria a API a qualquer site.
        $this->assertSame(self::PERMITIDA, $resposta->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $resposta->headers->get('Access-Control-Allow-Credentials'));

        $metodos = (string) $resposta->headers->get('Access-Control-Allow-Methods');
        $this->assertStringContainsString('POST', $metodos);

        $cabecalhos = strtolower((string) $resposta->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('x-xsrf-token', $cabecalhos);
    }

    public function test_a_origem_nao_permitida_nao_e_autorizada(): void
    {
        $terceiro = 'http://site-de-terceiro.test';

        $resposta = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $terceiro,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $autorizada = $resposta->headers->get('Access-Control-Allow-Origin');

        // O cabecalho nao ecoa a origem que pediu, e tambem nao vira curinga.
        // O navegador compara o valor recebido com a propria origem e recusa a
        // leitura quando eles diferem — e e essa comparacao que barra o
        // terceiro. Uma lista de origem unica responde sempre com a origem
        // configurada, e nao com a solicitada; o efeito de bloqueio e o mesmo.
        $this->assertNotSame($terceiro, $autorizada);
        $this->assertNotSame('*', $autorizada);
    }

    public function test_requisicao_real_de_origem_nao_permitida_nao_e_autorizada(): void
    {
        $terceiro = 'http://site-de-terceiro.test';

        $resposta = $this->call('GET', '/api/auth/me', [], [], [], [
            'HTTP_ORIGIN' => $terceiro,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $autorizada = $resposta->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame($terceiro, $autorizada);
        $this->assertNotSame('*', $autorizada);
    }

    public function test_nenhuma_origem_curinga_esta_configurada(): void
    {
        $origens = config('cors.allowed_origins');

        $this->assertNotContains('*', $origens);
        $this->assertContains(self::PERMITIDA, $origens);
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_o_cabecalho_do_token_e_aceito_na_requisicao(): void
    {
        // `X-XSRF-TOKEN` precisa ser aceito porque e o cabecalho que o cliente
        // **envia**. Nada aqui governa a leitura do cookie — isso e atributo do
        // proprio cookie, provado em AuthCookiesTest.
        $this->assertContains('X-XSRF-TOKEN', config('cors.allowed_headers'));

        // Nenhum cabecalho de resposta desta API precisa ser lido pelo cliente.
        $this->assertSame([], config('cors.exposed_headers'));
    }
}
