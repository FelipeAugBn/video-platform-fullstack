<?php

declare(strict_types=1);

namespace App\Video\Application\GetLessonVideo;

use App\Catalog\Application\Port\LessonRepository;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;

/**
 * O estado do video da aula, e a informacao publica de falha quando houver
 * (RF-VID-002).
 *
 * Leitura pura: sem transacao e sem trava. Nao ha nada a serializar — a consulta
 * nao decide nada, e travar a linha para responder a um `GET` faria consultas
 * simultaneas esperarem umas pelas outras sem disputar coisa alguma.
 *
 * **A aula e resolvida primeiro, e por dentro da consulta.** Aula alheia ou
 * inexistente responde `404` sem que a tentativa chegue a ser buscada
 * (RN-PROP-005): a ordem inversa consultaria o video de outro produtor antes de
 * recusar.
 *
 * Uma aula sem video devolve `null`, e nao um erro. "Ainda nao enviei nada" e uma
 * resposta legitima da consulta, e transforma-la em `404` obrigaria a interface
 * a tratar como falha o estado inicial de toda aula recem-criada.
 */
final class GetLessonVideo
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly VideoAttemptRepository $attempts,
    ) {}

    /**
     * @throws DomainException quando a aula nao existe ou nao e deste produtor
     */
    public function __invoke(GetLessonVideoQuery $consulta): ?VideoAttempt
    {
        if ($this->lessons->findOwned($consulta->lessonId, $consulta->ownerId) === null) {
            throw new DomainException(Failure::NOT_FOUND);
        }

        return $this->attempts->findCurrentOfLesson($consulta->lessonId);
    }
}
