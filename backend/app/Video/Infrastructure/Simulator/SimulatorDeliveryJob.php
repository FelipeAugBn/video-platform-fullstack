<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Simulator;

use App\Video\Domain\ProcessingScenario;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A entrega do desfecho pelo fornecedor simulado, na fila `simulator`
 * (plan §13.3).
 *
 * Roda no `simulator-worker`, processo separado do `worker` de proposito: o
 * simulador representa um provedor externo, com tempo de espera proprio, e uma
 * fila dele congestionada nao pode atrasar o trabalho da aplicacao.
 *
 * Como o `ProcessVideoJob`, carrega **apenas identificadores** — a tentativa e o
 * cenario. Deles sai o `event_id` estavel, o que faz uma reentrega ser
 * reconhecida como repeticao em vez de virar um evento novo.
 *
 * **Este job nao escreve em tabela de dominio, e nem poderia.** Ele nao conhece
 * repositorio nenhum: a unica coisa que ele faz e chamar {@see CallbackDelivery},
 * que fala com o webhook por HTTP. E a restricao arquitetural de plan §13.3, e
 * ela e verificavel — um teste confere que nada da area do simulador referencia
 * os repositorios do dominio.
 */
final class SimulatorDeliveryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $videoAttemptId,
        private readonly ProcessingScenario $scenario,
    ) {}

    public function handle(CallbackDelivery $entrega): void
    {
        $entrega->deliver($this->videoAttemptId, $this->scenario);
    }
}
