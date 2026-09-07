<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Controller;

use App\Shared\Interfaces\Http\Controller\IdentifiesTheOwner;
use App\Video\Application\GetOwnedPlayback\GetOwnedPlayback;
use App\Video\Application\GetOwnedPlayback\GetOwnedPlaybackQuery;
use App\Video\Interfaces\Http\Resource\PlaybackResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/lessons/{lesson}/video/playback` — o produtor confere o proprio
 * video.
 *
 * Uma acao so, e por isso um controller invocavel. Ele nao decide nada: a
 * propriedade, o estado do video e a emissao da URL pertencem ao caso de uso, e
 * as negativas viram resposta pelo tratamento central de erros — `404` para
 * aula inexistente ou alheia, `409` para video indisponivel, `503` para falha
 * transitoria ao assinar.
 *
 * **Separado de {@see PlaybackController}, e nao um parametro dele.** O que
 * difere entre os dois e a politica de acesso: um exige perfil `consumer`,
 * concessao e aula publicada; o outro exige perfil `producer` e propriedade, e
 * vale igualmente sobre rascunho. Um endereco unico obrigaria a rota a escolher
 * essa politica pelo perfil de quem chamou.
 *
 * O resto e compartilhado de fato, e nao por coincidencia: a exigencia de video
 * em `ready` com referencia, a emissao da URL, o prazo, a traducao da falha ao
 * assinar e este mesmo {@see PlaybackResource} vivem em um lugar so. Dois
 * controllers e dois casos de uso de autorizacao; uma implementacao que assina.
 *
 * O identificador chega como texto. Sem route model binding, pela mesma razao
 * das demais rotas do produtor: carregar a aula antes de saber de quem ela e
 * seria ler recurso alheio para so entao recusa-lo.
 */
final class OwnedPlaybackController
{
    use IdentifiesTheOwner;

    public function __construct(private readonly GetOwnedPlayback $obterReproducao) {}

    public function __invoke(Request $request, string $lesson): JsonResponse
    {
        $reproducao = ($this->obterReproducao)(new GetOwnedPlaybackQuery(
            lessonId: $lesson,
            ownerId: $this->donoAutenticado($request),
        ));

        return PlaybackResource::make($reproducao)->response();
    }
}
