<?php

declare(strict_types=1);

namespace App\Video\Application\HandleProcessingCallback;

use App\Video\Domain\WebhookOutcome;

/**
 * O que o callback produziu, para a fronteira traduzir em resposta.
 *
 * Dois desfechos e uma marca de repeticao. A marca nao muda a resposta — uma
 * reentrega de evento aceito continua respondendo `200`, e uma de evento
 * rejeitado continua respondendo `409` (plan §13.4) — e existe para que os
 * testes possam afirmar que **nenhum efeito novo** foi produzido, que e a
 * garantia de RN-IDM-002 e RN-IDM-003.
 *
 * A falha transitoria nao esta aqui de proposito: ela nao e um resultado, e sim
 * a ausencia de um. Representa-la como um terceiro caso conviveria com um
 * `return` normal, e o caminho dela e outro — a excecao sobe, a transacao
 * inteira sofre rollback e a reserva desaparece com ela (RN-VID-007).
 */
final class CallbackResult
{
    private function __construct(
        public readonly WebhookOutcome $outcome,
        public readonly bool $repeated,
    ) {}

    public static function aplicado(): self
    {
        return new self(WebhookOutcome::ACCEPTED, false);
    }

    public static function rejeitado(): self
    {
        return new self(WebhookOutcome::REJECTED_PERMANENT, false);
    }

    public static function repeticaoDe(WebhookOutcome $registrado): self
    {
        return new self($registrado, true);
    }

    public function foiAceito(): bool
    {
        return $this->outcome === WebhookOutcome::ACCEPTED;
    }
}
