<?php

declare(strict_types=1);

namespace App\Video\Application\GetPlayback;

use DateTimeImmutable;

/**
 * Os dados de reproducao — e nao o arquivo (RF-PLB-005, plan §14.2).
 *
 * Tres campos, e nenhum deles diz onde o objeto esta guardado: a URL ja vem
 * assinada e expira sozinha, e a chave do armazenamento nao atravessa esta
 * fronteira. `contentType` e o tipo **verificado** no objeto pela conclusao do
 * envio, e nao o que o cliente declarou.
 *
 * Imutavel e sem comportamento: e resultado de leitura.
 */
final class Playback
{
    public function __construct(
        public readonly string $url,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $contentType,
    ) {}
}
