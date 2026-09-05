<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

/**
 * O objeto confirmadamente nao existe no armazenamento.
 *
 * E a resposta de uma inspecao a uma chave que nunca foi gravada, ou que foi
 * removida. **Confirmadamente** e a palavra que importa: o armazenamento
 * respondeu, e a resposta foi a ausencia. Repetir a chamada nao muda o
 * resultado.
 *
 * A diferenca em relacao a {@see StorageUnavailable} e o que se sabe. La nao se
 * sabe nada; aqui existe evidencia.
 */
final class ObjectNotFound extends StorageFailure
{
    public static function em(string $operation, string $key): self
    {
        return new self(
            $operation,
            $key,
            "O objeto '{$key}' nao existe no armazenamento ({$operation}).",
        );
    }
}
