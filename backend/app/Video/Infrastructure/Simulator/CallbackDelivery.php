<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Simulator;

use App\Video\Domain\ProcessingEventId;
use App\Video\Domain\ProcessingScenario;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * O fornecedor externo simulado chamando o callback de verdade (plan §13.3).
 *
 * Este e o componente que faz a integracao valer alguma coisa. Um job que
 * alterasse a tabela de videos diretamente seria um atalho que **nunca testaria**
 * assinatura, idempotencia nem transicao — e sao justamente esses tres o miolo do
 * desafio. Aqui o unico caminho para afetar o estado e o mesmo que um provedor
 * real usaria: um `POST` assinado ao endpoint oficial.
 *
 * ## Restricao arquitetural
 *
 * **Nao escreve em tabela de dominio.** Esta classe nao conhece repositorio,
 * model nem conexao de banco — so o cliente HTTP, o segredo compartilhado e o
 * endereco do callback. A restricao e verificavel em revisao e em teste.
 *
 * ## As duas pontas assinam com o mesmo segredo
 *
 * `WEBHOOK_SECRET` e um valor so, compartilhado: este componente assina com ele e
 * o verificador do endpoint confere com ele. Dois segredos seriam duas verdades,
 * e toda entrega terminaria em `401`.
 *
 * ## O endereco e interno
 *
 * A chamada sai de dentro da rede do Compose e vai ao nome do servico que atende
 * HTTP, e nao ao endereco publico. O endereco publico e o do navegador; usa-lo
 * aqui faria a entrega depender do encaminhamento de porta do host, que nao
 * existe do ponto de vista do container.
 *
 * ## Politica de repeticao
 *
 * Repete diante de falha de rede e de `5xx` — inclusive o `503` temporario do
 * callback — lancando, para que a fila reentregue. Para diante de `200` e de
 * `409`, que sao desfechos definitivos: um instrui "aceito", o outro "nunca sera
 * aceito", e insistir em qualquer um deles seria ruido.
 *
 * Qualquer outra resposta — `401` de assinatura, `422` de carga — tambem faz o
 * job falhar, e de proposito: sao defeitos de configuracao ou de contrato entre
 * as duas pontas, e engoli-los em silencio esconderia justamente o problema que
 * precisa aparecer. Esgotadas as tentativas, o job termina em `failed_jobs` e a
 * tentativa permanece em `processing`, porque nenhum desfecho confiavel chegou.
 */
final class CallbackDelivery
{
    /**
     * Prefixo de versao da assinatura, no formato `v1=<hex>` (plan §13.4).
     */
    private const VERSAO_ASSINATURA = 'v1';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $callbackUrl,
        private readonly string $secret,
        private readonly int $timeout,
    ) {}

    /**
     * Entrega o desfecho do cenario para a tentativa informada.
     *
     * @throws RuntimeException quando a entrega precisa ser repetida pela fila
     */
    public function deliver(string $videoAttemptId, ProcessingScenario $scenario): void
    {
        // O corpo e serializado **uma vez** e assinado exatamente como sera
        // enviado. Serializar de novo para enviar mudaria os bytes — ordem de
        // chaves, escape de barras — e a assinatura nao conferiria do outro lado.
        $corpo = $this->corpo($videoAttemptId, $scenario);
        $timestamp = (string) time();

        try {
            $resposta = $this->http
                ->timeout($this->timeout)
                ->withBody($corpo, 'application/json')
                ->withHeaders([
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => $this->assinar($timestamp, $corpo),
                ])
                ->post($this->callbackUrl);
        } catch (ConnectionException) {
            // Sem resposta nenhuma: nao ha desfecho, e a fila reentrega o mesmo
            // evento. Nada da excecao original e propagado.
            throw new RuntimeException('A entrega do callback nao alcancou a API.');
        }

        $status = $resposta->status();

        if ($status === 200 || $status === 409) {
            return;
        }

        throw new RuntimeException("A entrega do callback foi respondida com {$status}.");
    }

    /**
     * A carga oficial do desafio, e nada alem dela.
     *
     * `playback_reference` acompanha o sucesso e e nulo na falha, como o contrato
     * exige. A referencia e derivada da chave do objeto para ser estavel entre
     * reentregas: um valor sorteado faria a segunda entrega do mesmo evento
     * carregar um conteudo diferente — inofensivo, porque o corpo da reentrega
     * nao e reavaliado, mas confuso para quem lesse os registros.
     */
    private function corpo(string $videoAttemptId, ProcessingScenario $scenario): string
    {
        return (string) json_encode([
            'event_id' => ProcessingEventId::para($videoAttemptId, $scenario),
            'video_id' => $videoAttemptId,
            'status' => $scenario->payloadStatus(),
            'playback_reference' => $scenario === ProcessingScenario::SUCCESS
                ? 'videos/'.$videoAttemptId.'/original.mp4'
                : null,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * HMAC-SHA256 sobre `timestamp + "." + corpo bruto`.
     */
    private function assinar(string $timestamp, string $corpo): string
    {
        return self::VERSAO_ASSINATURA.'='.hash_hmac('sha256', $timestamp.'.'.$corpo, $this->secret);
    }
}
