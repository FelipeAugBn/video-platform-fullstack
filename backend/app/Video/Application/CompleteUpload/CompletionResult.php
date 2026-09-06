<?php

declare(strict_types=1);

namespace App\Video\Application\CompleteUpload;

use App\Video\Domain\VideoAttempt;

/**
 * O resultado da conclusao: a tentativa como ela ficou, e o que aconteceu com
 * ela.
 *
 * Os dois campos existem porque um nao substitui o outro. A tentativa carrega o
 * estado e o motivo publico da falha, que e o que a resposta exibe; o desfecho
 * carrega **qual operacao ocorreu**, que e o que decide o status. Uma conclusao
 * repetida e uma conclusao nova podem devolver a mesma tentativa, no mesmo
 * estado, e ainda assim merecer respostas diferentes (ver
 * {@see CompletionOutcome}).
 */
final class CompletionResult
{
    private function __construct(
        public readonly VideoAttempt $attempt,
        public readonly CompletionOutcome $outcome,
    ) {}

    public static function aceita(VideoAttempt $tentativa): self
    {
        return new self($tentativa, CompletionOutcome::ACCEPTED);
    }

    public static function jaAceita(VideoAttempt $tentativa): self
    {
        return new self($tentativa, CompletionOutcome::ALREADY_ACCEPTED);
    }

    public static function rejeitada(VideoAttempt $tentativa): self
    {
        return new self($tentativa, CompletionOutcome::REJECTED);
    }
}
