<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Um trecho de uma lista, sem saber que existe HTTP.
 *
 * As portas de repositorio devolvem isto, e nao o paginador do framework. O
 * motivo e a regra de dependencia (plan §5.1): a camada Application nao importa
 * Eloquent, Query Builder nem `Illuminate\Pagination`, e um paginador do
 * framework atravessando a porta traria os tres de volta por uma janela lateral.
 *
 * Os itens sao objetos de dominio. A traducao para o corpo da resposta acontece
 * em Interfaces, onde o formato de `meta` e `links` foi decidido (plan §10.2).
 */
final class Page
{
    /**
     * @param  list<mixed>  $items
     */
    private function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $lastPage,
    ) {}

    /**
     * A ultima pagina e derivada, e nao recebida.
     *
     * Recebe-la abriria a possibilidade de um adapter informar um numero que nao
     * corresponde ao total e ao tamanho que ele mesmo entregou — inconsistencia
     * que so apareceria na navegacao do cliente, longe da causa.
     *
     * @param  list<mixed>  $items
     */
    public static function of(array $items, int $currentPage, int $perPage, int $total): self
    {
        $porPagina = max(1, $perPage);

        return new self(
            items: $items,
            currentPage: $currentPage,
            perPage: $perPage,
            total: $total,
            lastPage: max(1, (int) ceil($total / $porPagina)),
        );
    }
}
