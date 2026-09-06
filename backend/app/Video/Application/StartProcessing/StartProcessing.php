<?php

declare(strict_types=1);

namespace App\Video\Application\StartProcessing;

use App\Shared\Application\Port\TransactionManager;
use App\Video\Application\Port\ProcessingQueue;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\ProcessingScenario;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;

/**
 * Inicia — ou retoma — o processamento de uma tentativa (RF-PROC-001,
 * RF-PROC-002, plan §13.2).
 *
 * E o corpo do primeiro job da aplicacao. Ele **nao decide o desfecho** do
 * processamento: transiciona o estado e repassa o trabalho ao ator externo, que
 * respondera pelo callback.
 *
 * ## Os cinco estados de entrada
 *
 * | Estado sob trava | Acao                                                          |
 * | ---------------- | ------------------------------------------------------------- |
 * | `uploaded`       | Transiciona para `processing` e agenda a entrega, junto        |
 * | `processing`     | **Retomada**: nao transiciona, e agenda a entrega de novo      |
 * | `ready`/`failed` | Encerra sem efeito: um retry tardio nao regride nada           |
 * | `pending`        | Incompativel: nao ha o que processar                          |
 * | `uploading`      | Incompativel: o envio nem foi concluido                        |
 *
 * A leitura e **travada** porque as cinco linhas acima sao decisoes, e decidir
 * sobre um estado que pode mudar no meio e decidir duas vezes (plan §8.5).
 *
 * ## Por que a retomada reagenda a mesma entrega
 *
 * A transacao fecha a janela interna, nao a externa: o job pode ser devolvido
 * pela fila **depois** do commit e antes do reconhecimento, e nesse caso o retry
 * encontra a tentativa ja em `processing`. Encerrar ali deixaria o video parado
 * para sempre se a primeira entrega nunca tivesse sido criada.
 *
 * Reagendar e seguro porque a entrega carrega o **mesmo** `event_id` — derivado
 * da tentativa e do cenario, nunca sorteado (plan §13.2). O webhook idempotente
 * reconhece a segunda como repeticao da primeira e nao produz efeito novo.
 *
 * ## Estado e entrega no mesmo commit
 *
 * `processing` e a linha de `jobs` da entrega pertencem a **mesma transacao**,
 * pela mesma razao da conclusao de envio (plan §7.4): a fila `simulator` vive na
 * mesma conexao MySQL. Nao existe janela entre transicionar e agendar — as duas
 * coisas existem juntas ou nenhuma existe.
 *
 * ## Falha tecnica nao e desfecho de negocio
 *
 * Este caso de uso nunca leva a tentativa a `failed`. Um job que esgota
 * tentativas termina em `failed_jobs` e o video **permanece** como estava
 * (plan §13.1): a aplicacao nao recebeu desfecho nenhum, e confundir uma queda
 * de infraestrutura com um video defeituoso e exatamente o erro que a separacao
 * entre as duas coisas existe para evitar.
 */
final class StartProcessing
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly VideoAttemptRepository $attempts,
        private readonly ProcessingQueue $queue,
    ) {}

    public function __invoke(string $videoAttemptId): void
    {
        $this->transactions->transactional(function () use ($videoAttemptId): void {
            $tentativa = $this->attempts->lock($videoAttemptId);

            if ($tentativa === null) {
                // A tentativa sumiu entre o enfileiramento e a execucao. Nao ha
                // o que iniciar e nao ha erro a relatar: repetir o job nao a
                // traria de volta.
                return;
            }

            match ($tentativa->state()) {
                VideoState::UPLOADED => $this->iniciar($videoAttemptId, $tentativa->startProcessing()),
                VideoState::PROCESSING => $this->agendarEntrega($videoAttemptId),
                VideoState::PENDING,
                VideoState::UPLOADING,
                VideoState::READY,
                VideoState::FAILED => null,
            };
        });
    }

    private function iniciar(string $videoAttemptId, VideoAttempt $emProcessamento): void
    {
        $this->attempts->save($emProcessamento);
        $this->agendarEntrega($videoAttemptId);
    }

    private function agendarEntrega(string $videoAttemptId): void
    {
        $this->queue->scheduleDelivery($videoAttemptId, ProcessingScenario::SUCCESS);
    }
}
