<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * O relogio parado, so para os testes.
 *
 * Existe para tornar a data uma afirmacao de igualdade em vez de aproximacao.
 * Com o relogio do sistema, o maximo que um teste consegue dizer sobre o
 * `created_at` gravado e que ele caiu perto do agora — o que passaria tambem se
 * a aplicacao gravasse a data errada por alguns segundos, ou a data do banco em
 * vez da da operacao.
 *
 * E a contrapartida pratica da porta `Clock`: sem ela, este substituto nao teria
 * onde entrar.
 */
final class FrozenClock implements Clock
{
    private function __construct(private readonly DateTimeImmutable $instante) {}

    public static function at(string $instante): self
    {
        return new self(new DateTimeImmutable($instante, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->instante;
    }
}
