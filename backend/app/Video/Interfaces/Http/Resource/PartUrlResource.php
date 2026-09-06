<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Resource;

use App\Shared\Interfaces\Http\Resource\ApiResource;
use App\Video\Application\IssuePartUrl\PartUrl;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * A autorizacao de uma parte, como o cliente a ve.
 *
 * O instante de expiracao sai em ISO 8601 com deslocamento explicito, como todas
 * as datas do contrato: sem fuso, cada cliente teria de adivinhar qual usar — e
 * aqui o erro custaria uma renovacao pedida tarde demais.
 */
final class PartUrlResource extends ApiResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        $parte = $this->resource;
        assert($parte instanceof PartUrl);

        return [
            'url' => $parte->url,
            'expires_at' => $parte->expiresAt->format(DateTimeInterface::ATOM),
        ];
    }
}
