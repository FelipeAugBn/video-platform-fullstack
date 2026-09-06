<?php

declare(strict_types=1);

namespace App\Video\Domain;

/**
 * Qual desfecho a entrega ao fornecedor simulado vai produzir.
 *
 * Existe porque o `event_id` e derivado da tentativa **e do cenario**
 * (plan §13.3): sem o cenario, o callback de sucesso e o de falha da mesma
 * tentativa teriam o mesmo identificador, e o segundo seria descartado como
 * reentrega do primeiro. Com ele, os dois sao eventos distintos e cada um tem o
 * proprio desfecho definitivo.
 *
 * Sao dois casos porque o simulador tem dois comportamentos, e nenhum deles e
 * sorteado: o fluxo normal produz `SUCCESS` de forma deterministica
 * (RF-PROC-006), e `FAILURE` so acontece quando alguem o pede explicitamente,
 * pelo comando de demonstracao (plan §13.3).
 *
 * PHP puro, como todo o `Domain`.
 */
enum ProcessingScenario: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';

    /**
     * O `status` que a carga oficial do callback carrega para este cenario.
     *
     * A traducao vive aqui, e nao no simulador, para que o vocabulario da carga
     * do desafio — `ready` e `failed` — tenha um unico ponto de origem.
     */
    public function payloadStatus(): string
    {
        return match ($this) {
            self::SUCCESS => VideoState::READY->value,
            self::FAILURE => VideoState::FAILED->value,
        };
    }
}
