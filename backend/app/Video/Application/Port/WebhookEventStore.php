<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

use App\Video\Application\Port\Exception\TransientStoreFailure;
use App\Video\Domain\WebhookOutcome;

/**
 * O registro de idempotencia dos callbacks, no vocabulario da estrategia
 * (plan §8.4).
 *
 * Tres metodos, e a ordem entre eles **e** a regra. A estrategia e reservar
 * primeiro: verificar antes e inserir depois abre uma janela entre a leitura e a
 * escrita pela qual duas entregas simultaneas do mesmo evento passam juntas —
 * exatamente o cenario que o desafio manda considerar.
 *
 *     reserve()  tenta criar a reserva; `false` significa que o evento ja existe
 *     outcomeOf()  le o desfecho ja registrado, esperando a transacao concorrente
 *     settle()   grava o desfecho definitivo, antes do commit
 *
 * **Nenhuma linha commitada fica sem desfecho.** Os dois caminhos conclusivos
 * chamam `settle()` dentro da mesma transacao da reserva, e uma falha
 * transitoria desfaz a linha inteira no rollback — a reentrega posterior e
 * avaliada do zero (RN-VID-007).
 *
 * O corpo recebido **nao** e parametro de `outcomeOf()`, e isso e deliberado: a
 * reentrega repete o desfecho registrado sem que ninguem olhe o conteudo dela
 * (RN-IDM-003). Uma assinatura que aceitasse o corpo convidaria a compara-lo, e
 * a comparacao e justamente o que abriria caminho para uma segunda entrega do
 * mesmo `event_id` produzir efeito diferente da primeira.
 *
 * PHP puro, como toda a camada `Application`.
 */
interface WebhookEventStore
{
    /**
     * Tenta reservar o evento, gravando o instante da avaliacao junto.
     *
     * Devolve `false` quando o `event_id` ja tem linha — a colisao na UNIQUE e o
     * mecanismo, e nao um erro a tratar.
     *
     * `receivedVideoId` e guardado como veio; a referencia resolvida entra depois,
     * em `settle()`, porque na hora da reserva ainda nao se sabe se existe
     * tentativa correspondente.
     *
     * @throws TransientStoreFailure
     */
    public function reserve(string $eventId, string $receivedVideoId, string $receivedStatus): bool;

    /**
     * O desfecho ja registrado para este evento.
     *
     * Faz uma leitura **travada**: quando a colisao veio de uma transacao
     * concorrente ainda aberta, esta chamada espera ela terminar em vez de ler
     * um estado intermediario. Devolve `null` se a transacao concorrente sofreu
     * rollback e a linha desapareceu — nesse caso o evento nunca foi concluido, e
     * quem chamou avalia por conta propria.
     *
     * @throws TransientStoreFailure
     */
    public function outcomeOf(string $eventId): ?WebhookOutcome;

    /**
     * Grava o desfecho definitivo e a tentativa que a aplicacao conseguiu
     * resolver.
     *
     * `videoAttemptId` fica nulo quando o `video_id` recebido nao corresponde a
     * tentativa alguma — o evento ainda recebe desfecho, como rejeicao
     * permanente, para que o emissor pare de reentregar algo que jamais sera
     * aceito.
     *
     * @throws TransientStoreFailure
     */
    public function settle(string $eventId, ?string $videoAttemptId, WebhookOutcome $outcome): void;
}
