<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

use App\Video\Domain\ProcessingScenario;

/**
 * O agendamento de trabalho assincrono da area de video (plan §§13.1, 13.2).
 *
 * Duas operacoes, e nenhuma delas fala em fila, job, conexao ou driver. O caso
 * de uso diz **o que** precisa acontecer depois; qual fila recebe o trabalho e
 * como ele e serializado sao decisoes do adapter.
 *
 * ## O que estas assinaturas garantem por construcao
 *
 * Nenhuma recebe um agregado. Sao identificadores e um enum, e o motivo esta em
 * plan §13.1: um job que carrega a entidade serializada opera sobre uma copia
 * velha, e entre o enfileiramento e a execucao o estado pode ter mudado — a
 * cada callback, inclusive. Com a porta falando so em identificador, nao existe
 * caminho pelo qual um caso de uso passe um objeto ao job por descuido.
 *
 * ## A atomicidade nao esta aqui, e nao poderia estar
 *
 * A garantia de que o job e a mudanca de estado pertencem ao mesmo commit
 * (plan §7.4) nao vem desta interface: ela vem de o adapter usar a mesma conexao
 * MySQL do dominio, e de o caso de uso chamar estes metodos **dentro** da
 * transacao. Uma porta nao consegue exigir isso de quem a implementa, entao a
 * exigencia esta escrita nos casos de uso que a usam e provada por um teste de
 * integracao contra o banco real.
 *
 * PHP puro, como toda a camada `Application`.
 */
interface ProcessingQueue
{
    /**
     * Agenda o processamento da tentativa.
     *
     * Chamada **dentro** da transacao que grava `uploaded`: no rollback nao sobra
     * nem estado nem trabalho; no commit os dois passam a existir juntos.
     */
    public function scheduleProcessing(string $videoAttemptId): void;

    /**
     * Agenda a entrega do desfecho ao fornecedor simulado.
     *
     * Chamada **dentro** da transacao que grava `processing`, pela mesma razao.
     * O cenario acompanha o identificador porque e dele e do identificador da
     * tentativa que sai o `event_id` estavel (plan §13.2).
     */
    public function scheduleDelivery(string $videoAttemptId, ProcessingScenario $scenario): void;
}
