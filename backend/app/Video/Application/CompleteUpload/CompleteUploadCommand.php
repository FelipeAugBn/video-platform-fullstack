<?php

declare(strict_types=1);

namespace App\Video\Application\CompleteUpload;

use App\Video\Application\Port\CompletedPart;

/**
 * A intencao de concluir um envio, com os comprovantes que o navegador coletou.
 *
 * Os `ETags` chegam como o cliente os recebeu do armazenamento e sao repassados
 * como vieram. Eles **nao** sao prova de nada aqui: a prova de que o objeto
 * existe e confere e independente, feita pelo backend (plan §12.1, §12.5).
 */
final class CompleteUploadCommand
{
    /**
     * @param  list<CompletedPart>  $parts
     */
    public function __construct(
        public readonly string $attemptId,
        public readonly string $ownerId,
        public readonly array $parts,
    ) {}
}
