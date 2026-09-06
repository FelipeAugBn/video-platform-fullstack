<?php

declare(strict_types=1);

namespace App\Catalog\Interfaces\Http\Controller;

use App\Catalog\Application\CreateLesson\CreateLesson;
use App\Catalog\Application\CreateLesson\CreateLessonCommand;
use App\Catalog\Application\GetLesson\GetLesson;
use App\Catalog\Application\GetLesson\GetLessonQuery;
use App\Catalog\Application\PublishLesson\PublishLesson;
use App\Catalog\Application\PublishLesson\PublishLessonCommand;
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
 *
 * A publicacao entra aqui, e nao num controller proprio, porque o recurso
 * publicado e a aula: um `PublishController` separado daria dois lugares para
 * procurar o que acontece com o mesmo recurso. A coordenacao entre os tres
 * agregados que ela exige vive no caso de uso, que e onde ela pertence.
 */
final class LessonController
{
    use IdentifiesTheOwner;

    public function __construct(
        private readonly CreateLesson $criarAula,
        private readonly GetLesson $obterAula,
        private readonly PublishLesson $publicarAula,
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

    /**
     * `200` tanto na primeira publicacao quanto na repeticao (RN-IDM-004).
     *
     * Nao ha `201` aqui: publicar nao cria recurso nenhum — muda o estado de um
     * que ja existe. E nao ha status diferente para a republicacao, de proposito:
     * o cliente que repete a operacao quer saber que a aula esta publicada, e
     * essa e a mesma resposta nos dois casos. Distinguir obrigaria a interface a
     * tratar dois caminhos para o mesmo resultado.
     */
    public function publish(Request $request, string $lesson): JsonResponse
    {
        $aula = ($this->publicarAula)(new PublishLessonCommand(
            lessonId: $lesson,
            ownerId: $this->donoAutenticado($request),
        ));

        return LessonResource::make($aula)->response();
    }
}
