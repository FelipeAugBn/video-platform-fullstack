<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Queue;

use App\Video\Application\Port\ProcessingQueue;
use App\Video\Domain\ProcessingScenario;
use App\Video\Infrastructure\Simulator\SimulatorDeliveryJob;

/**
 * A porta de agendamento, sobre a Database Queue do Laravel (plan §13.1).
 *
 * E o unico arquivo da area de video que sabe que existem jobs e filas. Duas
 * linhas de corpo, e as duas importam pelo mesmo motivo: cada uma escolhe **qual
 * fila** recebe o trabalho, e essa escolha e o que separa os dois consumidores.
 *
 *   `default`    o `worker`, trabalho da propria aplicacao
 *   `simulator`  o `simulator-worker`, que representa o provedor externo
 *
 * Um consumidor so para as duas filas faria uma entrega simulada lenta atrasar o
 * inicio do processamento de outros videos, que e exatamente o acoplamento que
 * dois processos separados existem para evitar (plan §13.2).
 *
 * ## O que faz o enfileiramento ser atomico nao esta escrito aqui
 *
 * A garantia de plan §7.4 vem de duas coisas fora deste arquivo: a conexao
 * `database` da fila usar a **mesma** conexao MySQL do dominio, declarada em
 * `config/queue.php`, e os casos de uso chamarem estes metodos **dentro** da
 * transacao. Este adapter apenas despacha; se a fila fosse movida para Redis ou
 * SQS, nada aqui mudaria — e a atomicidade desapareceria em silencio. Por isso a
 * prova e um teste de integracao contra o banco real, e nao a leitura deste
 * codigo.
 */
final class QueuedProcessing implements ProcessingQueue
{
    private const FILA_SIMULADOR = 'simulator';

    public function scheduleProcessing(string $videoAttemptId): void
    {
        // Sem `onQueue`: a fila padrao do despacho e `default`, que e a que o
        // `worker` consome.
        ProcessVideoJob::dispatch($videoAttemptId);
    }

    public function scheduleDelivery(string $videoAttemptId, ProcessingScenario $scenario): void
    {
        SimulatorDeliveryJob::dispatch($videoAttemptId, $scenario)->onQueue(self::FILA_SIMULADOR);
    }
}
