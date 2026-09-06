<?php

declare(strict_types=1);

namespace App\Video\Application\HandleProcessingCallback;

use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Application\Port\WebhookEventStore;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;
use App\Video\Domain\WebhookOutcome;

/**
 * Aplica o callback de processamento, de forma idempotente pelo `event_id`
 * (RF-WHK-002 a 007, plan §8.4).
 *
 * ## Reservar primeiro, e nao verificar antes
 *
 * A chave da idempotencia e a UNIQUE em `webhook_events.event_id`, e a ordem das
 * operacoes **e** a garantia. Verificar se o evento ja existe e inserir depois
 * abriria uma janela entre a leitura e a escrita pela qual duas entregas
 * simultaneas do mesmo evento passariam juntas — exatamente o cenario que o
 * desafio manda considerar.
 *
 *     INSERT da reserva
 *       ├── colide na UNIQUE ──▶ le o desfecho registrado e o repete
 *       └── entra ─────────────▶ resolve a tentativa e decide
 *
 * A leitura do desfecho e travada: a segunda entrega **espera** a transacao
 * concorrente terminar em vez de correr com ela. Depois disso ela le um desfecho
 * definitivo — ou nao encontra linha alguma, se a primeira sofreu rollback, e ai
 * avalia o evento por conta propria.
 *
 * ## O corpo da reentrega nunca e reavaliado
 *
 * A colisao leva direto ao desfecho registrado, **sem olhar o que chegou**
 * (RN-IDM-003). Nao ha comparacao de conteudo em lugar nenhum deste arquivo, e
 * por isso nao existe caminho pelo qual uma segunda entrega do mesmo `event_id`
 * produza efeito diferente da primeira.
 *
 * ## Tres desfechos, e um deles nao deixa rastro
 *
 * | Situacao                                     | Efeito no video | Linha em `webhook_events` |
 * | -------------------------------------------- | --------------- | ------------------------- |
 * | Transicao valida a partir do estado travado  | Aplicada        | `accepted`                |
 * | Tentativa inexistente ou estado incompativel | **Preservado**  | `rejected_permanent`      |
 * | Falha transitoria de infraestrutura          | **Preservado**  | **Nenhuma** — rollback    |
 *
 * A terceira linha e a que distingue "este evento nunca podera ser aplicado" de
 * "nao consegui avalia-lo agora" (RN-VID-006 contra RN-VID-007). Sem ela, ou um
 * evento legitimo se perde, ou o emissor reentrega para sempre algo que jamais
 * sera aceito.
 *
 * ## Um evento sobre tentativa inexistente ainda recebe desfecho
 *
 * `received_video_id` nao tem chave estrangeira justamente para isto: a reserva
 * e criada mesmo quando nao ha tentativa correspondente, e o evento e registrado
 * como rejeicao permanente com `video_attempt_id` nulo. Com a chave, a insercao
 * falharia na constraint, aquele `event_id` ficaria eternamente sem desfecho e o
 * emissor reentregaria sem fim.
 *
 * ## A mensagem de falha e derivada, nao recebida
 *
 * Um callback `failed` grava `VIDEO_PROCESSING_FAILED` do catalogo fechado. A
 * carga nao tem campo de mensagem, e o agregado so aceita um caso da enumeracao —
 * RF-WHK-011 e RN-AUT-005 valem por construcao, e nao por disciplina.
 */
final class HandleProcessingCallback
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly WebhookEventStore $events,
        private readonly VideoAttemptRepository $attempts,
    ) {}

    public function __invoke(ProcessingCallbackCommand $comando): CallbackResult
    {
        return $this->transactions->transactional(
            fn (): CallbackResult => $this->aplicar($comando),
        );
    }

    private function aplicar(ProcessingCallbackCommand $comando): CallbackResult
    {
        $reservado = $this->events->reserve($comando->eventId, $comando->videoId, $comando->status);

        if (! $reservado) {
            $registrado = $this->events->outcomeOf($comando->eventId);

            if ($registrado !== null) {
                return CallbackResult::repeticaoDe($registrado);
            }

            // A transacao concorrente sofreu rollback e a linha desapareceu com
            // ela. O evento continua elegivel, e esta entrega o avalia do zero.
            $this->events->reserve($comando->eventId, $comando->videoId, $comando->status);
        }

        $tentativa = $this->attempts->lock($comando->videoId);

        if ($tentativa === null) {
            return $this->registrar($comando, null, WebhookOutcome::REJECTED_PERMANENT);
        }

        $destino = $comando->status === VideoState::READY->value
            ? VideoState::READY
            : VideoState::FAILED;

        if (! $tentativa->state()->canTransitionTo($destino)) {
            // Evento novo, incompativel com o estado travado. O video e
            // preservado e a rejeicao e definitiva: reentregar nao mudara nada,
            // e a resposta `409` instrui o emissor a parar (RN-VID-006).
            return $this->registrar($comando, $tentativa->id(), WebhookOutcome::REJECTED_PERMANENT);
        }

        $this->attempts->save($this->aplicarNoVideo($tentativa, $destino, $comando->playbackReference));

        return $this->registrar($comando, $tentativa->id(), WebhookOutcome::ACCEPTED);
    }

    private function aplicarNoVideo(VideoAttempt $tentativa, VideoState $destino, ?string $referencia): VideoAttempt
    {
        return $destino === VideoState::READY
            // A referencia e obrigatoria para `ready`, e a validacao estrutural
            // ja recusou a carga que nao a trouxe: chegar aqui com nulo seria um
            // furo na fronteira, e o `assert` o denuncia em vez de gravar um
            // video pronto e inassistivel.
            ? $tentativa->markReady($this->exigirReferencia($referencia))
            : $tentativa->fail(Failure::VIDEO_PROCESSING_FAILED);
    }

    private function exigirReferencia(?string $referencia): string
    {
        assert($referencia !== null && $referencia !== '');

        return (string) $referencia;
    }

    private function registrar(
        ProcessingCallbackCommand $comando,
        ?string $videoAttemptId,
        WebhookOutcome $desfecho,
    ): CallbackResult {
        $this->events->settle($comando->eventId, $videoAttemptId, $desfecho);

        return $desfecho === WebhookOutcome::ACCEPTED
            ? CallbackResult::aplicado()
            : CallbackResult::rejeitado();
    }
}
