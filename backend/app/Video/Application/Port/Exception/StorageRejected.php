<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

/**
 * O armazenamento recusou a operacao **por causa do que foi enviado**, em
 * definitivo.
 *
 * Reservada as recusas reconhecidas e permanentes sobre o proprio conteudo ou a
 * forma da operacao: uma parte que nao confere com a que foi gravada, partes
 * fora de ordem, parte menor que o minimo do protocolo. Sao situacoes em que o
 * armazenamento respondeu, a resposta foi nao, e o motivo diz respeito ao envio.
 *
 * **Recusa de acesso nao entra aqui.** Credencial, permissao, assinatura e
 * configuracao produzem {@see StorageUnavailable}, porque nao sao evidencia
 * sobre o arquivo — e a distincao existe justamente para que um problema de
 * configuracao nunca vire um video condenado.
 *
 * O conjunto e uma lista reconhecida, e nao um "tudo o mais": o que a aplicacao
 * nao souber classificar cai no lado seguro.
 */
final class StorageRejected extends StorageFailure
{
    public static function em(string $operation, string $key): self
    {
        return new self(
            $operation,
            $key,
            "O armazenamento recusou em definitivo a operacao sobre '{$key}' ({$operation}).",
        );
    }
}
