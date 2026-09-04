<?php

declare(strict_types=1);

namespace App\Shared\Interfaces\Http\Resource;

/**
 * Normaliza o tamanho de pagina pedido pelo cliente.
 *
 * Duas decisoes do contrato (plan §10.1): ausente vira 15, e acima de 50 e
 * **limitado** a 50, nunca recusado. Recusar transformaria um pedido ambicioso
 * em erro, quando a intencao — "me traga o maximo que der" — e atendivel; o
 * teto existe para proteger o banco e a resposta, nao para punir quem pediu.
 *
 * Recebe inteiro positivo. Texto, zero e negativo sao problema de validacao de
 * entrada, resolvido no Form Request de cada listagem, que responde `422` antes
 * de chegar aqui. Este componente trabalha sobre valor ja validado, e nao
 * duplica essa regra.
 */
final class PerPage
{
    public const PADRAO = 15;

    public const MAXIMO = 50;

    public static function of(?int $requested): int
    {
        if ($requested === null) {
            return self::PADRAO;
        }

        return min($requested, self::MAXIMO);
    }
}
