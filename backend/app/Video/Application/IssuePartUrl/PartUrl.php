<?php

declare(strict_types=1);

namespace App\Video\Application\IssuePartUrl;

use DateTimeImmutable;

/**
 * A autorizacao temporaria para o navegador enviar **uma** parte.
 *
 * O instante de expiracao acompanha a URL de proposito: sem ele o cliente teria
 * de recontar os quinze minutos por conta propria, a partir de um relogio que
 * nao e o do servidor. Com ele, renovar vira uma decisao verificavel — e a
 * renovacao e a mesma rota, sem estado a corrigir (plan §11.3).
 */
final class PartUrl
{
    public function __construct(
        public readonly string $url,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
