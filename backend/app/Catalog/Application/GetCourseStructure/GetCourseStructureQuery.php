<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetCourseStructure;

/**
 * Qual curso, para quem.
 *
 * Sem pagina e sem tamanho, de proposito: a estrutura e uma arvore e nao e
 * paginada (plan §10.1, RF-EST-002).
 */
final class GetCourseStructureQuery
{
    public function __construct(
        public readonly string $courseId,
        public readonly string $ownerId,
    ) {}
}
