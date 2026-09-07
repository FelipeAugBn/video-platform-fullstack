<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Resource;

use App\Shared\Interfaces\Http\Resource\ApiResource;
use App\Video\Application\IssuePlayback\Playback;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Os dados de reproducao, como o cliente os ve (plan §14.2).
 *
 * Tres campos, listados um a um, e nada alem deles. Nao saem daqui o
 * identificador da tentativa, o estado do video, a chave no armazenamento nem o
 * tamanho verificado: quem assiste precisa de um endereco, de um prazo e de um
 * tipo — o resto e informacao interna do envio.
 *
 * **Um formato para as duas operacoes.** A reproducao do consumidor e a
 * conferencia do produtor diferem na politica de acesso — perfil, base da
 * autorizacao e exigencia de publicacao —, e nao no que devolvem: a
 * disponibilidade exigida do video e a mesma, a emissao e a mesma, e o corpo e
 * este. Um segundo recurso com os mesmos tres campos so criaria um lugar a mais
 * para eles divergirem.
 *
 * `expires_at` acompanha a URL de proposito. Sem ela o player so descobriria o
 * vencimento ao receber uma recusa do armazenamento no meio da reproducao, e nao
 * teria como renovar antes.
 */
final class PlaybackResource extends ApiResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        $reproducao = $this->resource;
        assert($reproducao instanceof Playback);

        return [
            'playback_url' => $reproducao->url,
            // ISO 8601 com deslocamento explicito, como em `PartUrlResource`:
            // uma data sem fuso obrigaria cada cliente a adivinhar qual usar.
            'expires_at' => $reproducao->expiresAt->format(DateTimeInterface::ATOM),
            'content_type' => $reproducao->contentType,
        ];
    }
}
