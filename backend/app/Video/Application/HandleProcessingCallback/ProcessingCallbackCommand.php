<?php

declare(strict_types=1);

namespace App\Video\Application\HandleProcessingCallback;

/**
 * A carga oficial do callback, exatamente como o desafio a define.
 *
 * Quatro campos, e **nenhum a mais** (plan §13.4). Em particular, nao ha campo
 * de mensagem de erro: a informacao de falha exibida ao produtor e derivada pelo
 * backend a partir do catalogo fechado, e nao recebida. Um texto vindo de fora
 * que chega a tela e uma superficie de injecao que este escopo nao precisa
 * abrir, e o custo — a mensagem ser generica — e assumido.
 *
 * Chega ja validado estruturalmente pela fronteira: campos presentes, `status`
 * entre os aceitos e `video_id` sintaticamente UUID. Carga malformada nunca
 * alcanca este objeto, e por isso nunca gasta uma reserva de idempotencia
 * (plan §8.4).
 */
final class ProcessingCallbackCommand
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $videoId,
        public readonly string $status,
        public readonly ?string $playbackReference,
    ) {}
}
