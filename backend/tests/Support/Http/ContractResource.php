<?php

declare(strict_types=1);

namespace Tests\Support\Http;

use App\Shared\Interfaces\Http\Resource\ApiResource;

/**
 * Recurso minimo usado pelos testes do contrato.
 *
 * Existe para que a suite exercite o envelope sem inventar um endpoint de
 * negocio: o formato da resposta e o que esta sob teste, e nao o que a
 * aplicacao guarda. Vive em `tests/` justamente para nao existir na aplicacao.
 *
 * Declara um campo condicional de proposito. Os recursos reais vao ter varios —
 * um dado que so aparece para o dono, uma referencia que so existe depois do
 * processamento — e o marcador de ausencia precisa desaparecer do JSON tanto no
 * recurso individual quanto dentro de uma lista paginada. Sem um campo assim
 * aqui, a diferenca entre resolver o recurso corretamente e apenas chamar
 * `toArray()` passaria despercebida.
 *
 * @property array{id: string, title: string, published_at?: string} $resource
 */
final class ContractResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->resource['id'],
            'title' => (string) $this->resource['title'],
            'published_at' => $this->when(
                isset($this->resource['published_at']),
                fn (): string => (string) $this->resource['published_at'],
            ),
        ];
    }
}
