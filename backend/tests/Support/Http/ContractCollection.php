<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use App\Shared\Interfaces\Http\Resource\ApiResourceCollection;

/**
 * Colecao nomeada minima, para exercitar a base de colecao sem paginacao.
 */
final class ContractCollection extends ApiResourceCollection
{
    /** @var class-string<ContractResource> */
    public $collects = ContractResource::class;
}
