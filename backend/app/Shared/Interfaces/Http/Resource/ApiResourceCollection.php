<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Resource;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Base das coleções nomeadas, para quando a lista precisa de campos proprios
 * alem dos itens.
 *
 * A subclasse declara em `$collects` qual recurso representa cada item. Sem
 * isso a coleção nao sabe como transformar o que recebeu.
 *
 * Esta base nao pagina. Uma listagem paginada usa `PaginatedResponse`, onde
 * `meta` e `links` tem forma fixa e conferida por teste.
 */
abstract class ApiResourceCollection extends ResourceCollection
{
    public static $wrap = 'data';
}
