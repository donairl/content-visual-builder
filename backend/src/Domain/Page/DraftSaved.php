<?php

declare(strict_types=1);

namespace App\Domain\Page;

use DateTimeImmutable;

/**
 * Result of a successful draft compare-and-swap.
 */
final readonly class DraftSaved
{
    public function __construct(
        public int $version,
        public DateTimeImmutable $updatedAt,
    ) {}
}
