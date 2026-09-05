<?php

declare(strict_types=1);

namespace App\Video\Application\Port;

/**
 * O que o armazenamento observa sobre um objeto, sem baixa-lo.
 *
 * Sao os quatro dados que a verificacao de conclusao de envio precisa
 * confrontar com o que foi declarado na abertura (plan §12.3): a chave e a
 * esperada para aquela tentativa, o tamanho corresponde ao declarado, o tipo
 * esta entre os aceitos, e os metadados carregam o identificador da tentativa.
 *
 * **Quatro campos, e nada mais.** Nao ha `etag`, data de modificacao, classe de
 * armazenamento ou versao: nenhuma regra desta solucao os usa, e um campo
 * publicado e um campo que passa a ser mantido. O `etag`, em particular, ficaria
 * convidando alguem a trata-lo como checksum do video, que e justamente o que
 * plan §12.5 recusa.
 *
 * Esta classe **nao decide nada**. Ela relata o que foi observado; comparar com o
 * declarado e decidir o desfecho pertence ao caso de uso de conclusao, que chega
 * na T043.
 *
 * PHP puro: sem SDK, sem framework, sem HTTP.
 */
final class StoredObject
{
    /**
     * @param  string  $key  Chave do objeto no armazenamento.
     * @param  int  $size  Tamanho em bytes, como o armazenamento o reporta.
     * @param  string  $contentType  Tipo declarado no objeto.
     * @param  array<string, string>  $metadata  Metadados controlados pelo servidor, gravados na abertura.
     */
    public function __construct(
        public readonly string $key,
        public readonly int $size,
        public readonly string $contentType,
        public readonly array $metadata,
    ) {}
}
