<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Domain\Course;
use App\Shared\Application\Port\Clock;

/**
 * Cria o curso do produtor autenticado.
 *
 * Tres dependencias, e cada uma remove uma decisao do caso de uso: a identidade
 * vem da porta, o instante vem do relogio, e a persistencia vem do repositorio.
 * O que sobra aqui e a orquestracao — que e exatamente o que um caso de uso deve
 * ser (plan §5.2).
 *
 * O estado inicial nao aparece nesta classe. Ele e responsabilidade de
 * `Course::create`, e mante-lo la significa que nenhum caso de uso futuro pode
 * criar um curso ja `available` por descuido.
 */
final class CreateCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly Clock $clock,
    ) {}

    public function __invoke(CreateCourseCommand $comando): Course
    {
        $curso = Course::create(
            id: $this->courses->nextIdentity(),
            ownerId: $comando->ownerId,
            title: $comando->title,
            description: $comando->description,
            createdAt: $this->clock->now(),
        );

        $this->courses->save($curso);

        return $curso;
    }
}
