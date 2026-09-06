<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Controller;

use App\Shared\Domain\Failure\Failure;
use App\Shared\Interfaces\Http\Problem\ProblemDetails;
use App\Video\Application\HandleProcessingCallback\HandleProcessingCallback;
use App\Video\Application\HandleProcessingCallback\ProcessingCallbackCommand;
use App\Video\Application\Port\Exception\TransientStoreFailure;
use App\Video\Interfaces\Http\Request\ProcessingCallbackRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * `POST /api/webhooks/video-processing`, a rota nomeada pelo desafio.
 *
 * Fora de sessao e de CSRF: o emissor e um servico, e o que prova a origem e a
 * assinatura HMAC conferida pelo middleware antes deste controller.
 *
 * ## Tres respostas, e cada uma instrui o emissor a uma coisa diferente
 *
 * | Resposta | Significado                     | O emissor deve            |
 * | -------- | ------------------------------- | ------------------------- |
 * | `200`    | aplicado, ou repeticao de aplicado | parar                  |
 * | `409`    | nunca podera ser aplicado       | parar                     |
 * | `503`    | nao foi possivel avaliar agora  | **tentar de novo**        |
 *
 * Sem a distincao entre as duas ultimas, ou um evento legitimo se perde, ou o
 * emissor reentrega para sempre algo que jamais sera aceito (plan §13.4).
 *
 * **Sem `202`.** O callback e aplicado sincronamente, dentro da propria
 * requisicao: quando a resposta sai, a transicao ja ocorreu. `202` diria que ha
 * trabalho pendente a aceitar, e nao ha — esse status pertence a conclusao de
 * envio, que de fato deixa um job enfileirado.
 *
 * ## Por que a falha transitoria e tratada aqui, e nao no handler central
 *
 * O `Retry-After` e a parte acionavel da resposta, e ele depende de um valor de
 * configuracao que so faz sentido para este endpoint. Deixar isso para o
 * tratamento centralizado significaria ou um `500` generico sem cabecalho — que
 * nao instrui nada —, ou o handler passando a conhecer a politica de reentrega
 * de uma rota especifica.
 *
 * As duas excecoes capturadas cobrem os dois caminhos possiveis: a porta do
 * registro de eventos ja traduz a falha do banco, e `QueryException` alcanca o
 * que falhar em qualquer outra escrita da mesma transacao. Nenhuma delas
 * atravessa para o corpo — a resposta e montada a partir do catalogo, como todas
 * as outras.
 */
final class ProcessingCallbackController
{
    public function __construct(
        private readonly HandleProcessingCallback $aplicarCallback,
        private readonly int $retryAfterSeconds,
    ) {}

    public function __invoke(ProcessingCallbackRequest $request): JsonResponse
    {
        try {
            $resultado = ($this->aplicarCallback)(new ProcessingCallbackCommand(
                eventId: $request->validated('event_id'),
                videoId: $request->validated('video_id'),
                status: $request->validated('status'),
                playbackReference: $request->validated('playback_reference'),
            ));
        } catch (TransientStoreFailure|QueryException) {
            // A transacao ja sofreu rollback: a reserva desapareceu com ela, e a
            // reentrega do mesmo `event_id` sera avaliada do zero (RN-VID-007).
            return ProblemDetails::response(
                Failure::SERVICE_UNAVAILABLE,
                null,
                ['Retry-After' => (string) $this->retryAfterSeconds],
            );
        }

        if (! $resultado->foiAceito()) {
            return ProblemDetails::response(Failure::WEBHOOK_EVENT_REJECTED);
        }

        return new JsonResponse(
            ['data' => ['event_id' => $request->validated('event_id'), 'outcome' => $resultado->outcome->value]],
            Response::HTTP_OK,
        );
    }
}
