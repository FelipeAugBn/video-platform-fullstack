<?php

declare(strict_types=1);

namespace App\Video\Application\Service;

use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;

/**
 * "Me da esta tentativa, se ela for minha" — em um lugar so.
 *
 * O par de `ResolveOwnedCourse` e `ResolveOwnedModule` do catalogo, um agregado
 * adiante. A propriedade de uma tentativa nao esta nela: e herdada pela cadeia
 * `video_attempt -> lesson -> module -> course -> owner_id`, e quem a resolve e a
 * consulta.
 *
 * **`404` e nao `403`**, pelo mesmo motivo das outras duas (RN-PROP-005,
 * RN-AUT-006): `403` diria "existe, e voce nao pode", e quem varresse
 * identificadores separando `403` de `404` obteria a lista do que existe. Com a
 * mesma resposta para tentativa alheia e tentativa inexistente, tentar nao
 * ensina nada.
 */
final class ResolveOwnedAttempt
{
    public function __construct(private readonly VideoAttemptRepository $attempts) {}

    /**
     * A tentativa deste dono, com a linha travada ate o fim da transacao.
     *
     * @throws DomainException
     */
    public function locked(string $attemptId, string $ownerId): VideoAttempt
    {
        return $this->ouNaoEncontrada($this->attempts->lockOwned($attemptId, $ownerId));
    }

    /**
     * A tentativa deste dono, sem trava.
     *
     * Existe ao lado da versao travada porque a consulta de estado nao decide
     * nada: ela le e devolve. Travar uma linha para responder a um `GET`
     * serializaria consultas que nao disputam nada.
     *
     * @throws DomainException
     */
    public function read(string $attemptId, string $ownerId): VideoAttempt
    {
        return $this->ouNaoEncontrada($this->attempts->findOwned($attemptId, $ownerId));
    }

    /**
     * @throws DomainException
     */
    private function ouNaoEncontrada(?VideoAttempt $tentativa): VideoAttempt
    {
        if ($tentativa === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $tentativa;
    }
}
