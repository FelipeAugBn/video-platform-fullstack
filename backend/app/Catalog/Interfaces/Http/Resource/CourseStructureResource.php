<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Resource;

use App\Catalog\Application\View\CourseStructureView;
use App\Catalog\Application\View\LessonView;
use App\Catalog\Application\View\ModuleView;
use App\Shared\Interfaces\Http\Resource\ApiResource;
use Illuminate\Http\Request;

/**
 * O curso inteiro: seus campos, seus modulos e as aulas de cada um.
 *
 * **Os seis campos do curso vem de `CourseResource`, e nao sao reescritos aqui.**
 * A estrutura devolve o mesmo curso que `GET /api/courses/{course}` devolve, e
 * duas listas de campos para o mesmo recurso divergiriam no dia em que uma delas
 * mudasse. Reaproveitar garante que o curso tenha uma forma so, decidida em um
 * lugar so.
 *
 * O mesmo vale um nivel abaixo: as aulas saem por `LessonResource`, com os
 * mesmos seis campos da consulta individual.
 *
 * `resolve()`, e nao `toArray()`, ao aninhar. E o metodo que aplica a resolucao
 * normal do framework depois da transformacao — campos condicionais, valores
 * ausentes, normalizacao — e e tambem o que evita o envelope `data` se repetir
 * dentro de cada item. Chamar `toArray()` direto entregaria o marcador de
 * ausencia como se fosse um valor.
 *
 * **Sem `meta`, sem `links` e sem paginacao** (plan §10.1): a arvore e ordenada,
 * e paginar a ordem e o que RF-EST-002 exige nao fazer.
 */
final class CourseStructureResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $estrutura = $this->resource;
        assert($estrutura instanceof CourseStructureView);

        return array_merge(
            (new CourseResource($estrutura->course))->resolve($request),
            [
                'modules' => array_map(
                    fn (ModuleView $modulo): array => $this->modulo($modulo, $request),
                    $estrutura->modules,
                ),
            ],
        );
    }

    /**
     * Um modulo dentro da arvore: os mesmos quatro campos da listagem, mais as
     * aulas.
     *
     * O `ModuleResource` nao serve aqui sem adaptacao porque ele recebe o
     * agregado, e a arvore trabalha com a projecao — que e o que carrega as
     * aulas. Os quatro campos sao os mesmos, e o teste da estrutura afirma a
     * lista fechada.
     *
     * @return array<string, mixed>
     */
    private function modulo(ModuleView $modulo, Request $request): array
    {
        return [
            'id' => $modulo->id,
            'course_id' => $modulo->courseId,
            'title' => $modulo->title,
            'position' => $modulo->position,
            'lessons' => array_map(
                fn (LessonView $aula): array => (new LessonResource($aula))->resolve($request),
                $modulo->lessons,
            ),
        ];
    }
}
