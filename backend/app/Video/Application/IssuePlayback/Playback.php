<?php

declare(strict_types=1);

namespace App\Video\Application\IssuePlayback;

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
 *
 * Mora ao lado de {@see IssuePlayback} porque e o resultado **dela**, e nao de
 * um dos dois casos de uso que a chamam. Guardado dentro de um deles, o outro
 * teria de importar do vizinho para devolver o proprio resultado — e a leitura
 * sugeriria uma dependencia entre consumo e producao que nao existe.
 */
final class Playback
{
    public function __construct(
        public readonly string $url,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $contentType,
    ) {}
}
