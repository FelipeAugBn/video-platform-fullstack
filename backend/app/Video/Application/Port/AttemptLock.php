<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

use App\Video\Application\Port\Exception\AttemptLockUnavailable;

/**
 * Exclusao mutua da conclusao de uma tentativa, entre processos (plan §8.2).
 *
 * Existe porque `SELECT ... FOR UPDATE` **nao resolve este caso**. A conclusao
 * precisa chamar o armazenamento, e a transacao tem de ser fechada antes dessa
 * chamada (plan §7.4) — o lock de linha morre junto com ela, e duas requisicoes
 * simultaneas ficam livres para executar `CompleteMultipartUpload` sobre a mesma
 * tentativa. O que se precisa serializar e a operacao **inteira**, incluindo o
 * intervalo em que nao ha transacao aberta.
 *
 * A porta declara isso e nada mais: "execute esta operacao sem que outra
 * execucao para a mesma tentativa aconteca ao mesmo tempo". Nao fala em cache,
 * em lease, em tabela nem em como o lock e obtido — sao vocabulario do
 * mecanismo, e um caso de uso que os conhecesse ja estaria escolhendo
 * infraestrutura.
 *
 * **A liberacao e responsabilidade do adapter, e ela e obrigatoria.** Quem
 * implementa esta porta libera em `finally`: uma excecao no meio da conclusao
 * nao pode deixar a tentativa trancada ate o lease expirar, ou o produtor
 * ficaria sem poder repetir a conclusao justamente depois de uma falha.
 *
 * PHP puro, como toda a camada `Application`.
 */
interface AttemptLock
{
    /**
     * Executa a operacao com exclusividade sobre a tentativa e devolve o
     * resultado dela.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws AttemptLockUnavailable quando outra execucao ja detem a exclusividade
     */
    public function serialize(string $videoAttemptId, callable $operation): mixed;
}
