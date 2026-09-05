<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\CreateLesson\CreateLesson;
use App\Catalog\Application\CreateLesson\CreateLessonCommand;
use App\Catalog\Application\GetLesson\GetLesson;
use App\Catalog\Application\GetLesson\GetLessonQuery;
use App\Catalog\Interfaces\Http\Request\CreateLessonRequest;
use App\Catalog\Interfaces\Http\Resource\LessonResource;
use App\Shared\Interfaces\Http\Controller\IdentifiesTheOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * As aulas, na fronteira HTTP.
 *
 * As duas acoes entram por identificadores diferentes — a criacao pelo modulo, a
 * consulta pela propria aula — e as duas resolvem a propriedade pela mesma
 * cadeia, dentro da consulta: `lesson -> module -> course -> owner_id`
 * (RN-PROP-004).
 *
 * Sem route model binding, pelo motivo de sempre: carregar antes de saber de
 * quem e seria ler recurso alheio para so entao recusa-lo.
 */
final class LessonController
{
    use IdentifiesTheOwner;

    public function __construct(
        private readonly CreateLesson $criarAula,
        private readonly GetLesson $obterAula,
    ) {}

    public function store(CreateLessonRequest $request, string $module): JsonResponse
    {
        $aula = ($this->criarAula)(new CreateLessonCommand(
            moduleId: $module,
            ownerId: $this->donoAutenticado($request),
            title: $request->validated('title'),
        ));

        return LessonResource::make($aula)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $lesson): JsonResponse
    {
        $aula = ($this->obterAula)(new GetLessonQuery(
            lessonId: $lesson,
            ownerId: $this->donoAutenticado($request),
        ));

        return LessonResource::make($aula)->response();
    }
}
