<?php

declare(strict_types=1);

namespace App\Video\Interfaces\Http\Resource;

use App\Shared\Interfaces\Http\Resource\ApiResource;
use App\Video\Application\OpenUpload\UploadPlan;
use Illuminate\Http\Request;

/**
 * O plano de envio, como o cliente o ve (RF-UPL-003).
 *
 * Cinco campos, e nenhuma URL: elas sao pedidas parte a parte pela rota propria,
 * porque emitir todas aqui faria as ultimas vencerem antes de o navegador chegar
 * nelas (plan §11.2).
 */
final class UploadPlanResource extends ApiResource
{
    /**
     * @return array<string, string|int>
     */
    public function toArray(Request $request): array
    {
        $plano = $this->resource;
        assert($plano instanceof UploadPlan);

        return [
            'attempt_id' => $plano->attemptId,
            'storage_key' => $plano->storageKey,
            'upload_id' => $plano->uploadId,
            'part_size' => $plano->partSize,
            'part_count' => $plano->partCount,
        ];
    }
}
