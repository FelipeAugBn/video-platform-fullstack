<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

/**
 * O envio em partes nao existe mais no armazenamento.
 *
 * Acontece quando a conclusao chega depois de o envio ter sido descartado — ou
 * quando ela chega **duas vezes**, e a primeira ja o consumiu. Este segundo caso
 * e o que torna a situacao util de distinguir: ele nao significa que o envio
 * falhou, significa que talvez ja tenha dado certo, e quem responde isso e a
 * inspecao do objeto (plan §12.4).
 *
 * Por isso a classificacao para aqui. Concluir que o video falhou seria destruir
 * um envio de gigabytes por causa de uma resposta perdida.
 */
final class MultipartUploadNotFound extends StorageFailure
{
    public static function em(string $operation, string $key): self
    {
        return new self(
            $operation,
            $key,
            "O envio em partes de '{$key}' nao existe mais no armazenamento ({$operation}).",
        );
    }
}
