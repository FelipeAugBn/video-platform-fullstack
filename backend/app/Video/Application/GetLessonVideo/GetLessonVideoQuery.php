<?php

declare(strict_types=1);

namespace App\Video\Application\GetLessonVideo;

final class GetLessonVideoQuery
{
    public function __construct(
        public readonly string $lessonId,
        public readonly string $ownerId,
    ) {}
}
