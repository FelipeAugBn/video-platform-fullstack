<?php

declare(strict_types=1);

namespace App\Catalog\Application\CreateLesson;

/**
 * A intencao de criar uma aula, ja reduzida ao que a regra usa.
 *
 * Tres campos. Quatro coisas que o cliente **nao** escolhe nao tem propriedade
 * aqui e por isso nao tem caminho: `position`, calculada pelo backend
 * (RF-AUL-009); `published_at`, que nasce nulo porque a aula nasce rascunho
 * (RF-AUL-004); `current_video_attempt_id`, que nasce nulo porque a aula nasce
 * sem video (RF-AUL-005); e `video_state`, que nem sequer e um campo gravavel —
 * ele e lido da tentativa atual.
 */
final class CreateLessonCommand
{
    public function __construct(
        public readonly string $moduleId,
        public readonly string $ownerId,
        public readonly string $title,
    ) {}
}
