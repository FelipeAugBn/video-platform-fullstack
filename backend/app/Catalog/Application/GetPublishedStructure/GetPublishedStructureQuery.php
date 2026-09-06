<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetPublishedStructure;

/**
 * Qual curso, para qual consumidor.
 *
 * O consumidor vem da sessao, nunca do corpo ou da query string: um identificador
 * de consumidor escolhido por quem chama seria uma concessao que o proprio
 * solicitante declara sobre si mesmo.
 */
final class GetPublishedStructureQuery
{
    public function __construct(
        public readonly string $courseId,
        public readonly string $consumerId,
    ) {}
}
