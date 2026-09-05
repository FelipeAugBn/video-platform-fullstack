<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateLesson;

use App\Catalog\Application\Port\LessonRepository;
use App\Catalog\Application\Service\ResolveOwnedModule;
use App\Catalog\Application\View\LessonView;
use App\Catalog\Domain\Lesson;
use App\Shared\Application\Port\TransactionManager;

/**
 * Cria uma aula no fim de um modulo proprio.
 *
 * A operacao e a mesma de `CreateModule`, um nivel abaixo da arvore: trava a
 * linha do **modulo**, le a maior posicao entre as aulas daquele modulo, cria a
 * aula na seguinte e grava — tudo dentro de uma transacao so (plan §§7.4, 8.1).
 *
 * A diferenca que importa esta na trava. Ela e da linha do modulo, e nao da do
 * curso: duas aulas criadas ao mesmo tempo em **modulos diferentes** do mesmo
 * curso nao competem, porque nao disputam a mesma sequencia de posicoes. Travar
 * o curso serializaria as duas sem necessidade.
 *
 * A propriedade e resolvida na consulta que localiza o modulo, atravessando
 * `module -> course -> owner_id`. Modulo alheio e modulo inexistente produzem a
 * mesma recusa (RN-PROP-005).
 *
 * **A aula nasce rascunho e sem video** (RF-AUL-004, RF-AUL-005). Isso nao esta
 * escrito aqui: e responsabilidade de `Lesson::create`, e mante-lo la significa
 * que nenhum caso de uso futuro pode criar uma aula ja publicada por descuido.
 */
final class CreateLesson
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly ResolveOwnedModule $resolverModulo,
        private readonly LessonRepository $lessons,
    ) {}

    public function __invoke(CreateLessonCommand $comando): LessonView
    {
        return $this->transactions->transactional(function () use ($comando): LessonView {
            $modulo = $this->resolverModulo->locked($comando->moduleId, $comando->ownerId);

            $aula = Lesson::create(
                id: $this->lessons->nextIdentity(),
                moduleId: $modulo->id(),
                title: $comando->title,
                position: $this->lessons->nextPosition($modulo->id()),
            );

            $this->lessons->save($aula);

            // A projecao e montada a partir do que acabou de ser criado, sem uma
            // segunda ida ao banco: uma aula nova nao tem tentativa de video, e
            // consultar o estado dela seria perguntar ao banco algo que a regra
            // ja garante.
            return LessonView::ofNew($aula);
        });
    }
}
