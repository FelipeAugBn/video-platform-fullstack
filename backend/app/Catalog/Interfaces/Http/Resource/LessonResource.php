<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Resource;

use App\Catalog\Application\View\LessonView;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * A aula, como o cliente a ve.
 *
 * Seis campos, e os mesmos seis em toda parte: na criacao, na consulta
 * individual e dentro da estrutura. Uma aula que mudasse de forma conforme o
 * endereco de onde foi lida obrigaria o cliente a ter tres modelos para a mesma
 * coisa.
 *
 * Recebe a **projecao**, e nao o agregado, porque dois dos seis campos nao estao
 * em `Lesson`: `video_state` vem da tentativa atual, que e outro agregado
 * (plan §6.1). Uma aula recem-criada chega aqui pela mesma porta, com o estado
 * nulo por construcao.
 *
 * Os dois campos anulaveis saem como `null`, e nao ausentes: o cliente distingue
 * "rascunho" e "sem video" sem precisar testar a presenca da chave.
 */
final class LessonResource extends ApiResource
{
    /**
     * @return array<string, string|int|null>
     */
    public function toArray(Request $request): array
    {
        $aula = $this->resource;
        assert($aula instanceof LessonView);

        return [
            'id' => $aula->id,
            'module_id' => $aula->moduleId,
            'title' => $aula->title,
            'position' => $aula->position,
            // ISO 8601 com deslocamento explicito, como em `CourseResource`: uma
            // data sem fuso obrigaria cada cliente a adivinhar qual usar.
            'published_at' => $aula->publishedAt?->format(DateTimeInterface::ATOM),
            'video_state' => $aula->videoState?->value,
        ];
    }
}
