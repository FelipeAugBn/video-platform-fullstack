<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Resource;

use App\Catalog\Domain\Course;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * O curso, como o cliente o ve.
 *
 * Seis campos, listados um a um. Nao ha `toArray` derivado do model nem
 * espalhamento de atributos: o que chega ao cliente e escrito aqui, e acrescentar
 * uma coluna a tabela nao acrescenta nada a resposta por conta propria.
 *
 * `updated_at` fica de fora porque nenhum requisito o pede, e campo publicado e
 * campo que passa a ser mantido. `description` entra inteira — e conteudo do
 * produtor, nao detalhe interno.
 *
 * O recurso recebe o **agregado**, e nao o model. E o que fecha a regra de
 * dependencia no ultimo passo: se Eloquent chegasse ate aqui, a camada de
 * interface passaria a poder navegar relacionamentos e disparar consultas na hora
 * de montar a resposta.
 */
final class CourseResource extends ApiResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        $curso = $this->resource;
        assert($curso instanceof Course);

        return [
            'id' => $curso->id(),
            'title' => $curso->title(),
            'description' => $curso->description(),
            'owner_id' => $curso->ownerId(),
            'state' => $curso->state()->value,
            // ISO 8601 com deslocamento explicito. Uma data sem fuso obrigaria
            // cada cliente a adivinhar qual usar, e o navegador adivinharia o
            // local — deslocando a data na tela de quem esta fora do UTC.
            'created_at' => $curso->createdAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
