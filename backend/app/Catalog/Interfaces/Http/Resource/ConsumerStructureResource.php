<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Resource;

use App\Catalog\Application\View\ConsumerLessonView;
use App\Catalog\Application\View\ConsumerModuleView;
use App\Catalog\Application\View\ConsumerStructureView;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * A arvore do consumidor: o curso, seus modulos e as aulas publicadas de cada um.
 *
 * Os campos do curso vem de `CourseResource`, e nao sao reescritos aqui: e o
 * mesmo curso que o produtor consulta, e duas listas de campos para o mesmo
 * recurso divergiriam no dia em que uma delas mudasse.
 *
 * Um nivel abaixo a resposta **e** diferente, e por isso a aula nao reaproveita
 * `LessonResource`: a do produtor declara `video_state`, e o consumidor nao ve
 * estado de processamento (RF-EST-004). A garantia nao esta nesta escolha de
 * recurso — esta no tipo que ele recebe, {@see ConsumerLessonView}, que nao tem
 * o campo para expor.
 *
 * **Sem `meta`, sem `links` e sem paginacao** (plan §10.1): a arvore e ordenada,
 * e paginar a ordem e o que RF-EST-002 exige nao fazer.
 */
final class ConsumerStructureResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $estrutura = $this->resource;
        assert($estrutura instanceof ConsumerStructureView);

        return array_merge(
            (new CourseResource($estrutura->course))->resolve($request),
            [
                'modules' => array_map(
                    fn (ConsumerModuleView $modulo): array => $this->modulo($modulo),
                    $estrutura->modules,
                ),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function modulo(ConsumerModuleView $modulo): array
    {
        return [
            'id' => $modulo->id,
            'course_id' => $modulo->courseId,
            'title' => $modulo->title,
            'position' => $modulo->position,
            'lessons' => array_map(
                fn (ConsumerLessonView $aula): array => $this->aula($aula),
                $modulo->lessons,
            ),
        ];
    }

    /**
     * Cinco campos, e nenhum deles sobre o video.
     *
     * `published_at` sai sempre preenchida: a arvore do consumidor nao contem
     * rascunho, e o tipo da projecao ja garante isso.
     *
     * @return array<string, string|int>
     */
    private function aula(ConsumerLessonView $aula): array
    {
        return [
            'id' => $aula->id,
            'module_id' => $aula->moduleId,
            'title' => $aula->title,
            'position' => $aula->position,
            // ISO 8601 com deslocamento explicito, como nos demais recursos.
            'published_at' => $aula->publishedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
