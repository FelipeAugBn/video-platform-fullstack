<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublishLesson;

final class PublishLessonCommand
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $ownerId,
    ) {}
}
