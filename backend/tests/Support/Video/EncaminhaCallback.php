<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Encaminha a chamada HTTP do simulador para a aplicacao sob teste.
 *
 * O simulador chama o endpoint por HTTP de verdade — e isso e o ponto dele. Numa
 * suite, porem, o endereco configurado aponta para o servico do Compose, que
 * fala com o banco da **aplicacao**, e nao com o schema de testes. Deixar a
 * chamada sair de verdade faria o teste medir outro banco.
 *
 * A saida e interceptar o cliente HTTP e reentregar a **mesma requisicao** —
 * mesmos bytes de corpo, mesmos cabecalhos de assinatura — a aplicacao sob
 * teste. O que fica de fora e o TCP; tudo o mais e exercido pelo caminho real:
 * a assinatura e calculada pelo simulador e conferida pelo middleware, a carga
 * passa pela validacao estrutural, e o desfecho vem do caso de uso.
 *
 * Preservar o **corpo bruto** e obrigatorio aqui, e nao um detalhe: a
 * verificacao HMAC trabalha sobre os bytes recebidos, e reserializar o JSON no
 * caminho mudaria a ordem das chaves ou o escape das barras — a entrega passaria
 * a falhar por um motivo que nao existe em producao.
 */
trait EncaminhaCallback
{
    /**
     * As respostas devolvidas ao simulador, na ordem em que ele as recebeu.
     *
     * Propriedade, e nao valor de retorno: o encaminhamento e registrado durante
     * a entrega, depois de o metodo abaixo ja ter retornado.
     *
     * @var list<array{status: int, corpo: string}>
     */
    protected array $callbacksEncaminhados = [];

    protected function encaminharCallbacksParaAAplicacao(): void
    {
        Http::fake(function (Request $requisicao) {
            $resposta = $this->call(
                'POST',
                '/api/webhooks/video-processing',
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_WEBHOOK_TIMESTAMP' => $requisicao->header('X-Webhook-Timestamp')[0] ?? '',
                    'HTTP_X_WEBHOOK_SIGNATURE' => $requisicao->header('X-Webhook-Signature')[0] ?? '',
                ],
                content: $requisicao->body(),
            );

            $this->callbacksEncaminhados[] = [
                'status' => $resposta->status(),
                'corpo' => (string) $resposta->getContent(),
            ];

            return Http::response($resposta->getContent(), $resposta->status());
        });
    }
}
