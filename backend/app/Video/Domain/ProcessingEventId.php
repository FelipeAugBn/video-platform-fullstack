<?php

declare(strict_types=1);

namespace App\Video\Domain;

/**
 * O identificador do evento de processamento, derivado — nunca sorteado.
 *
 * Uma unica regra, num lugar so: **mesma tentativa e mesmo cenario produzem
 * sempre o mesmo identificador**. E disso que dependem todas as protecoes de
 * repeticao fora da requisicao HTTP (plan §8.6):
 *
 *   - o `ProcessVideoJob` repetido pela fila agenda de novo a **mesma** entrega,
 *     e o webhook a reconhece como repeticao em vez de aplicar duas vezes;
 *   - o simulador que reentrega apos falha de rede reenvia o **mesmo** evento;
 *   - o comando de demonstracao de falha, executado duas vezes, repete o
 *     desfecho ja registrado em vez de criar um segundo.
 *
 * Um identificador aleatorio por execucao transformaria cada retentativa em
 * evento novo e anularia a idempotencia inteira do callback — que e a garantia
 * central do desafio. Por isso a derivacao e explicita e legivel, e nao um hash:
 * quem le a linha de `webhook_events` durante uma demonstracao consegue dizer de
 * qual tentativa e de qual cenario ela veio.
 *
 * O formato cabe folgadamente no `VARCHAR(128)` da coluna: prefixo, cenario e um
 * UUID somam menos de sessenta caracteres.
 *
 * PHP puro, como todo o `Domain`.
 */
final class ProcessingEventId
{
    private const PREFIXO = 'video';

    public static function para(string $videoAttemptId, ProcessingScenario $cenario): string
    {
        return self::PREFIXO.'-'.$cenario->value.'-'.$videoAttemptId;
    }
}
