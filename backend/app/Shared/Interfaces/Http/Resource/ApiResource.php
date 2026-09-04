<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Resource;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base das respostas de recurso individual.
 *
 * O envelope `data` e declarado aqui, e nao repetido em cada recurso: o contrato
 * e do projeto inteiro (plan §10.1), e deixar cada classe escolher o proprio
 * invólucro seria como nao ter contrato nenhum.
 *
 * Coleções sem paginação saem de `ApiResource::collection()`, que aproveita o
 * mesmo envelope. Listagens paginadas usam `PaginatedResponse`, que acrescenta
 * `meta` e `links`.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * Repetido explicitamente mesmo coincidindo com o padrao do framework: o
     * formato da resposta e uma decisao registrada do projeto, e um padrao que
     * mude numa versao futura nao deve levar o contrato junto sem que ninguem
     * perceba.
     */
    public static $wrap = 'data';
}
