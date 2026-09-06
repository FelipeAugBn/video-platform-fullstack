<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

use RuntimeException;

/**
 * Outra execucao ja esta concluindo esta mesma tentativa.
 *
 * Nao e falha do video, e nao e erro de quem chamou: e a exclusao mutua da
 * conclusao funcionando (plan §8.2). O caso de uso a traduz em indisponibilidade
 * temporaria, e a repeticao encontra a tentativa ja resolvida e recebe o
 * desfecho que a primeira obteve — que e exatamente o comportamento de
 * RN-IDM-001.
 *
 * Por isso ela **nao** leva a tentativa a `failed`: nao ha evidencia nenhuma
 * sobre o objeto aqui, so contencao entre duas requisicoes.
 *
 * PHP puro: sem framework, sem cache, sem HTTP.
 */
final class AttemptLockUnavailable extends RuntimeException
{
    public static function para(string $videoAttemptId): self
    {
        return new self("Outra conclusao ja esta em andamento para a tentativa '{$videoAttemptId}'.");
    }
}
