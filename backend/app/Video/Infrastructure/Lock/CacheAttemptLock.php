<?php

declare(strict_types=1);

namespace App\Video\Infrastructure\Lock;

use App\Video\Application\Port\AttemptLock;
use App\Video\Application\Port\Exception\AttemptLockUnavailable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * A exclusao mutua da conclusao, sobre o lock atomico do cache (plan §8.2).
 *
 * ## Por que o store e nomeado, e nao o padrao
 *
 * O lock precisa ser visto por **todos os processos**: o `api` que atende a
 * requisicao e qualquer outro container da mesma imagem. Um lock em arquivo vive
 * no sistema de arquivos de um container, e um em memoria vive no processo —
 * nos dois casos, duas conclusoes simultaneas atendidas por processos diferentes
 * obteriam o lock ao mesmo tempo e a serializacao seria uma ilusao.
 *
 * O store vem por nome — `database`, as tabelas `cache` e `cache_locks` do
 * proprio MySQL — e nao do padrao configurado. Nao e desconfianca da
 * configuracao: a suite de testes usa deliberadamente o store `array`, e sob ele
 * este lock nao serializaria nada. Nomear aqui e o que garante que o teste de
 * exclusao mutua exerca o mesmo mecanismo que roda em execucao.
 *
 * ## Lease maior que o tempo do storage
 *
 * O lease precisa cobrir a conclusao inteira, incluindo as duas chamadas ao
 * armazenamento. Se ele expirasse com a chamada ainda em voo, uma segunda
 * requisicao adquiriria o lock e as duas passariam a concluir a mesma tentativa
 * em paralelo — que e exatamente o que este arquivo existe para impedir. Por
 * isso o valor vem da configuracao junto dos tempos limite do cliente S3, e nao
 * escrito a mao aqui.
 *
 * ## Espera curta, e nao espera indefinida
 *
 * Quem chega durante uma conclusao em andamento espera alguns segundos e
 * desiste. Bloquear ate o lease inteiro prenderia um processo PHP-FPM pelo tempo
 * da operacao alheia, e um punhado de repeticoes esgotaria o pool. Desistir
 * devolve indisponibilidade temporaria, e a repeticao encontra a tentativa ja
 * resolvida e recebe o desfecho registrado — que e o comportamento de
 * RN-IDM-001 chegando pelo caminho mais barato.
 *
 * ## A liberacao e em `finally`
 *
 * `Lock::block()` do framework libera automaticamente ao fim da funcao anonima,
 * inclusive quando ela lanca. A liberacao explicita em `finally` permanece
 * porque a garantia precisa estar visivel neste arquivo: uma excecao no meio da
 * conclusao nao pode deixar a tentativa trancada ate o lease expirar, ou o
 * produtor ficaria sem poder repetir justamente depois de uma falha.
 */
final class CacheAttemptLock implements AttemptLock
{
    /**
     * O nome do lock, como plan §8.2 o especifica.
     */
    private const PREFIXO = 'video-upload-complete:';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly string $store,
        private readonly int $leaseSeconds,
        private readonly int $waitSeconds,
    ) {}

    public function serialize(string $videoAttemptId, callable $operation): mixed
    {
        $lock = $this->cache->store($this->store)->lock(
            self::PREFIXO.$videoAttemptId,
            $this->leaseSeconds,
        );

        try {
            return $lock->block($this->waitSeconds, static fn (): mixed => $operation());
        } catch (LockTimeoutException) {
            throw AttemptLockUnavailable::para($videoAttemptId);
        } finally {
            $lock->release();
        }
    }
}
