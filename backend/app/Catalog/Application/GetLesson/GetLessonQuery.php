<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetLesson;

/**
 * Qual aula, para quem.
 *
 * O par obrigatorio de sempre. O dono nao e um filtro aplicado depois: ele entra
 * na consulta que atravessa `lesson -> module -> course -> owner_id`.
 */
final class GetLessonQuery
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $ownerId,
    ) {}
}
