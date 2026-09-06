<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Domain\VideoState;

/**
 * O video da aula nao satisfaz as condicoes de publicacao (RF-PUB-002,
 * RN-PUB-002, RN-PUB-003).
 *
 * Carrega o caso do catalogo — qual condicao faltou — **e** o estado do video,
 * que sai como membro de extensao do problema. A interface precisa dos dois:
 * o codigo diz o que exibir, e o estado diz se o produtor deve esperar,
 * reenviar o arquivo ou nao ter feito nada (RF-UI-010).
 *
 * O caso do catalogo vem de fora em vez de ser fixo porque as condicoes sao
 * mais de uma e nao se resumem ao estado: um video em `ready` **sem** referencia
 * de reproducao tambem e inelegivel, e o estado sozinho nao explicaria a recusa.
 *
 * PHP puro, como todo o `Domain`.
 */
final class VideoNotPublishable extends DomainException
{
    public function __construct(Failure $failure, public readonly VideoState $videoState)
    {
        parent::__construct($failure);
    }

    /**
     * @return array<string, string>
     */
    public function extensions(): array
    {
        return ['video_state' => $this->videoState->value];
    }
}
