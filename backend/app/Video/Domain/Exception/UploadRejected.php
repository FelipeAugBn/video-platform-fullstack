<?php

declare(strict_types=1);

namespace App\Video\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Domain\VideoState;

/**
 * Um novo envio foi pedido enquanto a tentativa atual da aula ainda esta ativa
 * (RF-UPL-011).
 *
 * Carrega **qual** estado impede, e nao apenas que algo impede. AC-VID-011 exige
 * que a resposta informe isso, e a diferenca e pratica: "o video ainda esta
 * sendo enviado" e "o video esta sendo processado" levam o produtor a esperas
 * diferentes, e uma delas nem sequer depende dele.
 *
 * O estado sai como membro de extensao do corpo do problema (RFC 9457), e nao
 * como um codigo proprio por estado: cinco codigos quase identicos no catalogo
 * diriam a mesma coisa cinco vezes, e a interface teria de conhecer os quatro
 * para exibir uma frase. O valor entregue e o da enumeracao, que ja e publico —
 * ele aparece em `video_state` na consulta da aula e na do video.
 *
 * PHP puro, como todo o `Domain`.
 */
final class UploadRejected extends DomainException
{
    public function __construct(public readonly VideoState $blockingState)
    {
        parent::__construct(Failure::VIDEO_ATTEMPT_ACTIVE);
    }

    /**
     * @return array<string, string>
     */
    public function extensions(): array
    {
        return ['video_state' => $this->blockingState->value];
    }
}
