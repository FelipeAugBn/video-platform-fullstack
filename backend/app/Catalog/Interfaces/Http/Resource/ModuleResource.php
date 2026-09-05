<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Resource;

use App\Catalog\Domain\Module;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use Illuminate\Http\Request;

/**
 * O modulo, como o cliente o ve.
 *
 * Quatro campos, listados um a um. Nao ha `toArray` derivado do model nem
 * espalhamento de atributos: o que chega ao cliente e escrito aqui, e acrescentar
 * uma coluna a tabela nao acrescenta nada a resposta por conta propria.
 *
 * `created_at` e `updated_at` ficam de fora porque nenhum requisito os pede, e
 * campo publicado e campo que passa a ser mantido. A ordem do modulo ja e dita
 * por `position`.
 *
 * O recurso recebe o **agregado**, e nao o model. E o que fecha a regra de
 * dependencia no ultimo passo: com Eloquent aqui, a camada de interface passaria
 * a poder navegar relacionamentos e disparar consultas ao montar a resposta.
 */
final class ModuleResource extends ApiResource
{
    /**
     * @return array<string, string|int>
     */
    public function toArray(Request $request): array
    {
        $modulo = $this->resource;
        assert($modulo instanceof Module);

        return [
            'id' => $modulo->id(),
            'course_id' => $modulo->courseId(),
            'title' => $modulo->title(),
            'position' => $modulo->position(),
        ];
    }
}
