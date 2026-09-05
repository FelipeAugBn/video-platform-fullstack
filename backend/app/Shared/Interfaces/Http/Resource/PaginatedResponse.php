<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Resource;

use App\Shared\Application\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginador;
use Illuminate\Pagination\Paginator;

/**
 * Resposta de listagem paginada, no formato aprovado (plan §10.1).
 *
 * O corpo e montado aqui, campo a campo, em vez de aproveitar a paginacao
 * automatica do framework. A razao e o contrato: a forma padrao acrescenta
 * chaves que o contrato nao declara — caminho da rota, primeiro e ultimo item
 * da pagina, uma segunda lista de links — e o cliente passaria a receber campos
 * que ninguem prometeu manter. Aqui `meta` e `links` tem exatamente os quatro
 * campos de cada, e um teste conferindo a estrutura inteira falha assim que
 * alguem acrescentar um quinto.
 *
 * O envelope tambem nao se duplica: os itens ja chegam transformados, e o
 * recurso individual nao volta a embrulhar cada um em `data`.
 */
final class PaginatedResponse implements Responsable
{
    /**
     * @param  class-string<ApiResource>|null  $resource  Recurso aplicado a cada item; nulo entrega o item como veio.
     */
    private function __construct(
        private readonly LengthAwarePaginator $page,
        private readonly ?string $resource,
    ) {}

    /**
     * @param  class-string<ApiResource>|null  $resource
     */
    public static function of(LengthAwarePaginator $page, ?string $resource = null): self
    {
        return new self($page, $resource);
    }

    /**
     * A mesma resposta, a partir do resultado neutro da camada Application.
     *
     * O repositorio devolve uma `Page` — itens de dominio, pagina, tamanho e
     * total —, porque a porta nao pode conhecer `Illuminate\Pagination`
     * (plan §5.1). A traducao para o paginador do framework acontece **aqui**,
     * na camada de interface, que e onde `meta` e `links` foram decididos.
     *
     * O caminho dos links vem da requisicao em curso, e nao e recebido por
     * parametro: e o mesmo comportamento do paginador padrao do framework, e
     * passa-lo adiante faria cada controller repetir a mesma linha.
     */
    public static function ofPage(Page $page, ?string $resource = null): self
    {
        $paginador = new Paginador(
            items: $page->items,
            total: $page->total,
            perPage: $page->perPage,
            currentPage: $page->currentPage,
            options: ['path' => Paginator::resolveCurrentPath()],
        );

        // Os links precisam carregar o tamanho da pagina.
        //
        // Sem isto, `next` apontaria apenas para `?page=2`, e seguir o proprio
        // link que a API entregou devolveria o cliente ao padrao de 15 — as
        // paginas mudariam de tamanho no meio da navegacao, e itens seriam
        // pulados ou repetidos. Paginacao cujos links nao sao navegaveis nao e
        // paginacao.
        //
        // `withQueryString` preserva o que mais vier na consulta, para que um
        // filtro acrescentado numa tarefa futura sobreviva a navegacao sem que
        // este arquivo precise saber dele. O `page` fica de fora por conta do
        // proprio paginador: cada link escreve o seu.
        $paginador->withQueryString();

        // O tamanho anunciado e o **efetivo**, e nao o que foi pedido. Quem
        // pediu 500 recebeu 50, e o link precisa dizer 50: repetir o pedido
        // original faria o link prometer uma pagina que a API nao entrega.
        $paginador->appends('per_page', (string) $page->perPage);

        return new self($paginador, $resource);
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'data' => $this->items($request),
            'meta' => [
                'current_page' => $this->page->currentPage(),
                'per_page' => $this->page->perPage(),
                'total' => $this->page->total(),
                'last_page' => $this->page->lastPage(),
            ],
            'links' => [
                'first' => $this->page->url(1),
                // Nulos na primeira e na ultima pagina, e nao ausentes: o
                // cliente distingue "nao existe pagina anterior" sem precisar
                // testar a presenca da chave.
                'prev' => $this->page->previousPageUrl(),
                'next' => $this->page->nextPageUrl(),
                'last' => $this->page->url($this->page->lastPage()),
            ],
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function items(Request $request): array
    {
        $items = $this->page->items();

        if ($this->resource === null) {
            return array_values($items);
        }

        // `resolve()`, e nao `toArray()`: e o metodo que aplica a resolucao
        // normal do framework depois da transformacao — campos condicionais
        // declarados com `when()`, valores ausentes, recursos aninhados e a
        // normalizacao da representacao. Chamar `toArray()` direto entregaria o
        // marcador de ausencia como se fosse um valor, e um campo que deveria
        // sumir apareceria como `{}` dentro da lista.
        return array_map(
            fn (mixed $item): array => (new ($this->resource)($item))->resolve($request),
            array_values($items),
        );
    }
}
