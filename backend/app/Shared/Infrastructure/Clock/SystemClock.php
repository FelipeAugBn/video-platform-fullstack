<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Clock;

use App\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * O relogio de verdade, o unico que le a hora da maquina.
 *
 * UTC declarado explicitamente, e nao herdado da configuracao do processo. As
 * datas gravadas atravessam container, banco e navegador; deixar o fuso implicito
 * significaria que a mesma operacao produz instantes diferentes conforme onde o
 * processo subiu, e a diferenca so apareceria em producao.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
