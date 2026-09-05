<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListCourses;

use App\Catalog\Application\Port\CourseRepository;
use App\Shared\Application\Page;

/**
 * Os cursos do produtor autenticado, paginados.
 *
 * O caso de uso e curto de proposito: ele nao filtra, nao ordena e nao conta. Se
 * fizesse qualquer uma dessas coisas, teria recebido antes uma lista maior do que
 * deveria — e "maior do que deveria" aqui significa contendo curso de outro
 * produtor. O recorte pertence a consulta SQL (RN-PROP-002), e a porta ja
 * devolve so o que e do dono.
 */
final class ListCourses
{
    public function __construct(private readonly CourseRepository $courses) {}

    public function __invoke(ListCoursesQuery $consulta): Page
    {
        return $this->courses->pageOfOwner(
            ownerId: $consulta->ownerId,
            page: $consulta->page,
            perPage: $consulta->perPage,
        );
    }
}
