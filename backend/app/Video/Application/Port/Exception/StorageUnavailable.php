<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

/**
 * **A aplicacao nao obteve evidencia confiavel** — e a pergunta continua sem
 * resposta.
 *
 * O nome fala de indisponibilidade porque esse e o caso mais comum, mas a
 * situacao e mais ampla, e a definicao e a segunda linha: sempre que nao houver
 * evidencia suficiente para condenar o envio, a falha e esta. Cobre:
 *
 *   - o armazenamento nao respondeu: conexao recusada, tempo esgotado, falha de
 *     rede;
 *   - respondeu pedindo para tentar de novo;
 *   - respondeu recusando **por um motivo que nao e sobre o conteudo enviado** —
 *     acesso, credencial, assinatura, configuracao;
 *   - respondeu recusando por um motivo que a aplicacao nao reconhece.
 *
 * Os dois ultimos casos sao os que exigem cuidado. Uma credencial errada ou um
 * endereco mal configurado **nao provam nada sobre o arquivo do produtor**:
 * trata-los como recusa definitiva faria uma configuracao errada marcar videos
 * legitimos como defeituosos, e o produtor perderia envios de gigabytes por um
 * problema que nao e dele. Diante da duvida, a classificacao preserva o estado
 * (plan §12.4).
 *
 * O preco dessa escolha e assumido: um erro de configuracao pode ser repetido
 * varias vezes antes de alguem perceber que a causa nao era transitoria. E o
 * lado seguro do erro.
 */
final class StorageUnavailable extends StorageFailure
{
    public static function em(string $operation, string $key): self
    {
        return new self(
            $operation,
            $key,
            "O armazenamento nao deu resposta confiavel sobre '{$key}' ({$operation}).",
        );
    }
}
