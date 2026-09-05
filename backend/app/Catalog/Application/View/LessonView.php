<?php

declare(strict_types=1);

namespace App\Catalog\Application\View;

use App\Catalog\Domain\Lesson;
use App\Video\Domain\VideoState;
use DateTimeImmutable;

/**
 * A aula como ela e **lida** — o agregado mais o estado do video dela.
 *
 * Existe porque a resposta de aula tem um campo que o agregado nao tem e nao
 * deve ter. `Lesson` guarda o identificador da tentativa atual, e nao o estado
 * dela: a tentativa e outro agregado, com ciclo de vida proprio (plan §6.1), e
 * copiar o estado dela para dentro da aula criaria uma segunda verdade que
 * envelhece a cada callback.
 *
 * A alternativa seria o caso de uso buscar a tentativa depois de carregar a
 * aula — uma consulta a mais por aula, que na estrutura de um curso viraria uma
 * consulta por aula do curso inteiro. A projecao resolve isso na consulta, de
 * uma vez (RF-EST-004).
 *
 * Imutavel e sem comportamento: e um resultado de leitura, nao um objeto de
 * dominio. Nao valida, nao decide e nao pode ser salva — quem escreve trabalha
 * com `Lesson`.
 */
final class LessonView
{
    public function __construct(
        public readonly string $id,
        public readonly string $moduleId,
        public readonly string $title,
        public readonly int $position,
        public readonly ?DateTimeImmutable $publishedAt,
        public readonly ?VideoState $videoState,
    ) {}

    /**
     * A visao de uma aula recem-criada.
     *
     * O estado do video e nulo **por construcao**, e nao por omissao: uma aula
     * nasce sem tentativa de video (RF-AUL-004), entao nao existe estado a
     * consultar. Buscar no banco o que a regra ja garante seria uma consulta para
     * confirmar o que acabou de ser escrito.
     */
    public static function ofNew(Lesson $lesson): self
    {
        return new self(
            id: $lesson->id(),
            moduleId: $lesson->moduleId(),
            title: $lesson->title(),
            position: $lesson->position(),
            publishedAt: $lesson->publishedAt(),
            videoState: null,
        );
    }
}
