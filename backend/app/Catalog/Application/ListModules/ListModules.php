<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListModules;

use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Application\Service\ResolveOwnedCourse;
use App\Catalog\Domain\Module;

/**
 * Os modulos de um curso proprio, em ordem.
 *
 * **Duas consultas, e a primeira nao e desperdicio.** O curso e resolvido antes
 * de listar porque uma lista vazia nao distingue "o curso e seu e nao tem
 * modulos" de "o curso nao e seu" — e as duas situacoes precisam de respostas
 * diferentes: `200` com colecao vazia na primeira, `404` na segunda (RF-MOD-003,
 * RN-PROP-005). Sem a resolucao, um produtor descobriria o curso alheio pela
 * ausencia de erro.
 *
 * A ordenacao nao acontece aqui. Ela vem da consulta, por `position ASC`, porque
 * a ordem faz parte do contrato e precisa valer para qualquer chamador da porta:
 * ordenar no consumidor dependeria de cada um lembrar de fazer, e um que
 * esquecesse receberia do banco uma sequencia sem garantia (RN-ORD-004).
 *
 * @see ModuleRepository::listOfOwnedCourse a consulta ordenada e filtrada
 */
final class ListModules
{
    public function __construct(
        private readonly ResolveOwnedCourse $resolverCurso,
        private readonly ModuleRepository $modules,
    ) {}

    /**
     * @return list<Module>
     */
    public function __invoke(ListModulesQuery $consulta): array
    {
        $curso = ($this->resolverCurso)($consulta->courseId, $consulta->ownerId);

        return $this->modules->listOfOwnedCourse($curso->id(), $consulta->ownerId);
    }
}
