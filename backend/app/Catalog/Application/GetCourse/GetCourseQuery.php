<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetCourse;

/**
 * Qual curso, para quem.
 *
 * Os dois campos andam juntos por obrigacao: nao ha construtor que aceite apenas
 * o identificador do curso. E a mesma escolha da porta, repetida na entrada do
 * caso de uso, para que a propriedade nao dependa de alguem lembrar de checa-la.
 */
final class GetCourseQuery
{
    public function __construct(
        public readonly string $courseId,
        public readonly string $ownerId,
    ) {}
}
