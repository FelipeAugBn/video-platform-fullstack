<?php

declare(strict_types=1);

namespace App\Catalog\Application\ListGrantedCourses;

use App\Catalog\Application\Port\ConsumerCatalogReadModel;
use App\Shared\Application\Page;

/**
 * Os cursos que o consumidor autenticado pode assistir, paginados
 * (RF-CONS-001, RF-CONS-002).
 *
 * Curto pelo mesmo motivo de `ListCourses`: ele nao filtra, nao ordena e nao
 * conta. Se fizesse qualquer uma dessas coisas, teria recebido antes uma lista
 * maior do que deveria — e "maior do que deveria" aqui significa contendo curso
 * sem concessao. As duas condicoes, concessao e `available`, pertencem a
 * consulta SQL, e a porta ja devolve so o que pode ser visto.
 *
 * A pagina de um consumidor sem nenhuma concessao e vazia, e nao um erro: nao
 * ter acesso a nada e um estado legitimo, e transforma-lo em recusa obrigaria a
 * interface a tratar como falha a tela inicial de quem ainda nao recebeu curso
 * nenhum.
 */
final class ListGrantedCourses
{
    public function __construct(private readonly ConsumerCatalogReadModel $catalog) {}

    public function __invoke(ListGrantedCoursesQuery $consulta): Page
    {
        return $this->catalog->pageOfConsumer(
            consumerId: $consulta->consumerId,
            page: $consulta->page,
            perPage: $consulta->perPage,
        );
    }
}
