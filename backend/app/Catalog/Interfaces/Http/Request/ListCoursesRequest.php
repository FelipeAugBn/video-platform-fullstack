<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Request;

use App\Shared\Interfaces\Http\Resource\PerPage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A entrada de `GET /api/courses`.
 *
 * Os dois parametros sao opcionais, e a diferenca de tratamento entre eles e
 * deliberada:
 *
 *   `per_page` **acima do teto** e atendido, limitado a 50. A intencao de quem
 *   pediu e legitima e realizavel; o teto existe para proteger a resposta, nao
 *   para punir o pedido.
 *
 *   `per_page` **zero, negativo ou nao inteiro** e recusado com `422`. Aqui nao
 *   ha intencao a atender: nao existe pagina de tamanho zero, e escolher um
 *   padrao no lugar do valor invalido esconderia do cliente que ele mandou algo
 *   que nao faz sentido.
 *
 * O teto nao aparece como `max` na validacao justamente para que ultrapassa-lo
 * nao vire erro. Ele e aplicado depois, ao converter para o valor efetivo.
 */
final class ListCoursesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'page' => 'pagina',
            'per_page' => 'itens por pagina',
        ];
    }

    public function pagina(): int
    {
        return $this->has('page') ? $this->integer('page') : 1;
    }

    /**
     * O tamanho efetivo: o padrao quando ausente, o teto quando excessivo.
     */
    public function porPagina(): int
    {
        return PerPage::of($this->has('per_page') ? $this->integer('per_page') : null);
    }
}
