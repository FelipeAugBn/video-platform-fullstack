<?php

declare(strict_types=1);

namespace App\Catalog\Application\GetLesson;

use App\Catalog\Application\Port\CatalogReadModel;
use App\Catalog\Application\View\LessonView;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;

/**
 * Uma aula propria, com o estado do video atual.
 *
 * Usa a porta de leitura, e nao o repositorio do agregado, porque a resposta tem
 * um campo que `Lesson` nao carrega: `video_state` vive em `video_attempts`, e a
 * aula guarda so o identificador da tentativa atual (plan §6.1). Pela porta de
 * leitura, as duas coisas vem numa consulta so; pelo repositorio, viriam em
 * duas.
 *
 * A recusa e a mesma dos demais recursos do catalogo: `404` para aula
 * inexistente e para aula de outro produtor, sem diferenca observavel entre os
 * dois (RN-PROP-005, RN-AUT-006). Nao ha `403` aqui — perfil errado ja foi
 * recusado pelo middleware, e isso nao revela recurso nenhum.
 */
final class GetLesson
{
    public function __construct(private readonly CatalogReadModel $catalogo) {}

    public function __invoke(GetLessonQuery $consulta): LessonView
    {
        $aula = $this->catalogo->lessonOfOwner($consulta->lessonId, $consulta->ownerId);

        if ($aula === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $aula;
    }
}
