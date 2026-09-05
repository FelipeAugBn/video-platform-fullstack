<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetCourse;

use App\Catalog\Application\Service\ResolveOwnedCourse;
use App\Catalog\Domain\Course;

/**
 * Um curso proprio.
 *
 * Delega inteiramente ao servico de propriedade, e nao ao repositorio: e ele que
 * concentra a decisao de recusar com `NOT_FOUND` em vez de `FORBIDDEN`, e essa
 * decisao precisa ser identica em todos os caminhos que alcancam um curso.
 */
final class GetCourse
{
    public function __construct(private readonly ResolveOwnedCourse $resolver) {}

    public function __invoke(GetCourseQuery $consulta): Course
    {
        return ($this->resolver)($consulta->courseId, $consulta->ownerId);
    }
}
