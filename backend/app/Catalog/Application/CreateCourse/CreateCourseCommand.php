<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateCourse;

/**
 * A intencao de criar um curso, ja reduzida ao que a regra usa.
 *
 * Sao tres campos, e nenhum deles e opcional. Repare no que **nao** esta aqui:
 * identificador, estado e data de criacao. Eles nao foram esquecidos — eles nao
 * podem chegar de fora. Nao ha propriedade nesta classe para recebe-los, entao
 * um corpo de requisicao que os traga nao tem por onde influenciar o resultado,
 * independentemente do que a validacao deixe passar (RN-PROP-001).
 *
 * `ownerId` vem da sessao, e nao do corpo. Quem monta este comando e o
 * controller, a partir do usuario autenticado.
 */
final class CreateCourseCommand
{
    public function __construct(
        public readonly string $ownerId,
        public readonly string $title,
        public readonly string $description,
    ) {}
}
