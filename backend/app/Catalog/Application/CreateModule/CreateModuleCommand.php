<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateModule;

/**
 * A intencao de criar um modulo, ja reduzida ao que a regra usa.
 *
 * Tres campos, e repare no que **nao** esta aqui: `position`. Ela nao foi
 * esquecida — nao ha propriedade nesta classe para recebe-la, entao um corpo de
 * requisicao que a traga nao tem por onde influenciar o resultado,
 * independentemente do que a validacao deixe passar (RF-MOD-007, RN-ORD-002). A
 * posicao e calculada pelo caso de uso, sob a trava do curso.
 *
 * `ownerId` vem da sessao, e nao do corpo. Ele viaja junto do curso porque a
 * propriedade e verificada na mesma consulta que localiza o curso.
 */
final class CreateModuleCommand
{
    public function __construct(
        public readonly string $courseId,
        public readonly string $ownerId,
        public readonly string $title,
    ) {}
}
