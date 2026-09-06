<?php

declare(strict_types=1);

namespace App\Video\Application\IssuePartUrl;

final class IssuePartUrlCommand
{
    public function __construct(
        public readonly string $attemptId,
        public readonly string $ownerId,
        public readonly int $partNumber,
    ) {}
}
