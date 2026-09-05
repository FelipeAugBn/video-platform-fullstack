<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetCourseStructure;

use App\Catalog\Application\Port\CatalogReadModel;
use App\Catalog\Application\View\CourseStructureView;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;

/**
 * O curso proprio com modulos e aulas, de uma vez (RF-EST-001).
 *
 * O caso de uso e curto porque a parte dificil e a consulta, e ela pertence ao
 * adapter. O que este arquivo garante e o contorno: o curso precisa ser deste
 * dono, e a ausencia vira a mesma recusa de sempre.
 *
 * **Nada e montado aqui em PHP.** Percorrer modulos buscando as aulas de cada um
 * seria o N+1 que a task proibe, e a ordem faz parte do contrato: sem `ORDER BY`
 * na consulta, o MySQL nao promete sequencia nenhuma. A porta de leitura devolve
 * a arvore ja aninhada e ja ordenada por posicao (RF-EST-002).
 *
 * A estrutura **nao** cria um agregado novo. `Course`, `Module` e `Lesson`
 * continuam sendo raizes separadas, cada uma com seu repositorio (plan §6.1);
 * isto e uma representacao para consulta, que nao pode ser salva.
 */
final class GetCourseStructure
{
    public function __construct(private readonly CatalogReadModel $catalogo) {}

    public function __invoke(GetCourseStructureQuery $consulta): CourseStructureView
    {
        $estrutura = $this->catalogo->structureOfOwner($consulta->courseId, $consulta->ownerId);

        if ($estrutura === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $estrutura;
    }
}
