<?php

declare(strict_types=1);

namespace App\Video\Application\Port\Exception;

use RuntimeException;

/**
 * A persistencia falhou por um motivo que pode nao se repetir.
 *
 * Existe para o unico desfecho do callback que **nao** e uma decisao de negocio
 * (RN-VID-007): o banco recusou, esgotou tempo de espera de trava ou perdeu a
 * conexao, e a aplicacao nao chegou a decidir nada sobre o evento. O tratamento
 * e devolver `5xx` com `Retry-After` e deixar a reentrega ser avaliada do zero —
 * o oposto de gravar um desfecho.
 *
 * **A traducao acontece no adapter**, e nao no caso de uso. Sem ela, o caso de
 * uso teria de capturar a excecao de consulta do framework para distinguir
 * "falhou e pode voltar" de "falhou de vez", e a camada `Application` passaria a
 * conhecer o mecanismo de persistencia pelo caminho do tratamento de erro — que
 * e o mais facil de nao notar em revisao.
 *
 * Como nas falhas da porta de armazenamento, nada do original atravessa: nem a
 * consulta, nem os valores vinculados, nem a mensagem do driver. A excecao
 * original **nao e encadeada**, porque `getPrevious()` levaria a consulta e seus
 * parametros para qualquer relatorio que a imprimisse.
 *
 * PHP puro: sem framework, sem PDO, sem HTTP.
 */
final class TransientStoreFailure extends RuntimeException
{
    public static function em(string $operacao): self
    {
        return new self("A persistencia nao concluiu a operacao '{$operacao}'.");
    }
}
