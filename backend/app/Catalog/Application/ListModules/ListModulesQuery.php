<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListModules;

/**
 * Quais modulos, de quem.
 *
 * Os dois campos andam juntos por obrigacao, como em toda consulta do catalogo:
 * nao ha construtor que aceite apenas o identificador do curso.
 *
 * Nao ha pagina nem tamanho. A listagem de modulos de um curso nao e paginada
 * (plan §10.1): ela e a ordem de uma arvore pequena, e paginar a ordem e o que a
 * spec exige nao fazer.
 */
final class ListModulesQuery
{
    public function __construct(
        public readonly string $courseId,
        public readonly string $ownerId,
    ) {}
}
