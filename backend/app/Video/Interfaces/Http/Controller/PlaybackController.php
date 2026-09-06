<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Controller;

use App\Shared\Interfaces\Http\Controller\IdentifiesTheConsumer;
use App\Video\Application\GetPlayback\GetPlayback;
use App\Video\Application\GetPlayback\GetPlaybackQuery;
use App\Video\Interfaces\Http\Resource\PlaybackResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/lessons/{lesson}/playback` — a rota nomeada literalmente pelo
 * desafio.
 *
 * Uma acao so, e por isso um controller invocavel. Ele nao decide nada: a
 * concessao, a publicacao, o estado do video e a emissao da URL pertencem ao
 * caso de uso, e as negativas viram resposta pelo tratamento central de erros —
 * `404` para acesso, `409` para indisponibilidade, `503` para falha transitoria
 * ao assinar.
 *
 * O identificador chega como texto. Sem route model binding, pela mesma razao
 * das demais rotas: carregar a aula antes de saber se ha concessao seria ler
 * conteudo para so entao recusa-lo.
 */
final class PlaybackController
{
    use IdentifiesTheConsumer;

    public function __construct(private readonly GetPlayback $obterReproducao) {}

    public function __invoke(Request $request, string $lesson): JsonResponse
    {
        $reproducao = ($this->obterReproducao)(new GetPlaybackQuery(
            lessonId: $lesson,
            consumerId: $this->consumidorAutenticado($request),
        ));

        return PlaybackResource::make($reproducao)->response();
    }
}
