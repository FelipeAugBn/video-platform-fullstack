<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishLesson;

use App\Catalog\Application\Port\CourseRepository;
use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Application\Port\ModuleRepository;
use App\Catalog\Application\View\LessonView;
use App\Catalog\Domain\Exception\VideoNotPublishable;
use App\Catalog\Domain\Lesson;
use App\Shared\Application\Port\Clock;
use App\Shared\Application\Port\TransactionManager;
use App\Shared\Domain\Exception\DomainException;
use App\Shared\Domain\Failure\Failure;
use App\Video\Application\Port\VideoAttemptRepository;
use App\Video\Domain\VideoAttempt;
use App\Video\Domain\VideoState;

/**
 * Publica uma aula propria, quando o video permite (RF-PUB-001 a 003,
 * plan §§8.3, 14.1).
 *
 * E a operacao que coordena **tres agregados** numa transacao so: a aula que
 * passa a publicada, a tentativa de video que autoriza isso, e o curso que
 * ganha disponibilidade na primeira vez. Nenhum dos tres contem os outros
 * (plan §6.1), entao a coordenacao e explicita e o lock e visivel — que era
 * justamente a troca aceita ao separa-los.
 *
 * ## A ordem das travas
 *
 * `lessons` → `video_attempts` → `courses`, sempre. A ordem e a mesma em todas
 * as execucoes de proposito: travas adquiridas em ordens diferentes por
 * caminhos diferentes sao a receita de um impasse entre transacoes, e a unica
 * defesa barata contra isso e a ordem ser uma so.
 *
 * ## Tres codigos de recusa, e nao um `409` generico
 *
 * RF-UI-010 pede que a interface mostre a condicao nao satisfeita, e "esta aula
 * nao tem video", "o video ainda esta processando" e "o video esta pronto mas
 * sem referencia de reproducao" levam o produtor a acoes diferentes — esperar,
 * enviar um arquivo, ou procurar suporte. Um codigo unico devolveria as tres
 * como a mesma coisa.
 *
 * O estado do video acompanha a recusa como membro de extensao do problema, pelo
 * mesmo motivo da recusa de novo envio: sem ele a interface faria uma segunda
 * requisicao so para descobrir o que ja estava decidido aqui.
 *
 * ## Republicar nao produz efeito
 *
 * A aula ja publicada devolve `200` sem nova escrita (RN-PUB-005, RN-IDM-004), e
 * **sem passar pela verificacao do video**: uma aula publicada cujo video
 * tivesse sido substituido depois continuaria publicada, e reavaliar a
 * elegibilidade numa operacao que nao muda nada produziria uma recusa para uma
 * situacao que ja e fato consumado.
 *
 * ## A promocao do curso e condicional, nao contada
 *
 * `Course::makeAvailable()` e idempotente, entao este caso de uso o chama a cada
 * publicacao sem antes perguntar se e a primeira. Contar aulas publicadas para
 * decidir seria uma consulta a mais e uma corrida a mais: duas publicacoes
 * simultaneas em aulas diferentes do mesmo curso poderiam ambas concluir que sao
 * a primeira. Com a trava do curso e a idempotencia do agregado, a segunda
 * simplesmente nao escreve nada (plan §8.3).
 */
final class PublishLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LessonRepository $lessons,
        private readonly ModuleRepository $modules,
        private readonly CourseRepository $courses,
        private readonly VideoAttemptRepository $attempts,
        private readonly Clock $clock,
    ) {}

    public function __invoke(PublishLessonCommand $comando): LessonView
    {
        return $this->transactions->transactional(function () use ($comando): LessonView {
            $aula = $this->lessons->lockOwned($comando->lessonId, $comando->ownerId);

            if ($aula === null) {
                throw new DomainException(Failure::NOT_FOUND);
            }

            if (! $aula->isDraft()) {
                return $this->visaoDe($aula);
            }

            $tentativa = $this->exigirVideoPublicavel($aula);

            $publicada = $aula->publish($this->clock->now());
            $this->lessons->save($publicada);

            $this->promoverCurso($publicada, $comando->ownerId);

            return $this->visaoDe($publicada, $tentativa->state());
        });
    }

    /**
     * A tentativa atual da aula, se ela autorizar a publicacao.
     *
     * @throws DomainException
     */
    private function exigirVideoPublicavel(Lesson $aula): VideoAttempt
    {
        $id = $aula->currentVideoAttemptId();

        if ($id === null) {
            throw new DomainException(Failure::LESSON_WITHOUT_VIDEO);
        }

        $tentativa = $this->attempts->lock($id);

        if ($tentativa === null) {
            // A aula aponta para uma tentativa que nao existe mais. Do ponto de
            // vista de quem publica, e o mesmo que nao ter video.
            throw new DomainException(Failure::LESSON_WITHOUT_VIDEO);
        }

        if ($tentativa->state() !== VideoState::READY) {
            throw new VideoNotPublishable(Failure::LESSON_VIDEO_NOT_READY, $tentativa->state());
        }

        if ($tentativa->playbackReference() === null) {
            throw new VideoNotPublishable(
                Failure::LESSON_PLAYBACK_REFERENCE_MISSING,
                $tentativa->state(),
            );
        }

        return $tentativa;
    }

    /**
     * A primeira publicacao do curso o torna disponivel (RF-PUB-003).
     */
    private function promoverCurso(Lesson $aula, string $ownerId): void
    {
        $modulo = $this->modules->lockOwned($aula->moduleId(), $ownerId);

        if ($modulo === null) {
            // A cadeia da propriedade ja foi percorrida ao resolver a aula, entao
            // este caminho so existe se o modulo tiver sumido dentro da propria
            // transacao. Nao ha curso a promover.
            return;
        }

        $curso = $this->courses->lockOwned($modulo->courseId(), $ownerId);

        if ($curso !== null) {
            $this->courses->save($curso->makeAvailable());
        }
    }

    private function visaoDe(Lesson $aula, ?VideoState $estado = null): LessonView
    {
        return new LessonView(
            id: $aula->id(),
            moduleId: $aula->moduleId(),
            title: $aula->title(),
            position: $aula->position(),
            publishedAt: $aula->publishedAt(),
            // No caminho da publicacao o estado ja esta em maos — a tentativa
            // acabou de ser lida sob trava para decidir a elegibilidade. So a
            // republicacao consulta, e sem trava: ela nao decide nada.
            videoState: $estado ?? $this->attempts->findCurrentOfLesson($aula->id())?->state(),
        );
    }
}
