<?php

declare(strict_types=1);

namespace App\Domain\Site;

use DateTimeImmutable;

/**
 * A site setting as stored: its decoded value and the version used for compare-and-swap saves.
 * Version 0 means "never saved" (the value is the schema default).
 */
final readonly class StoredSetting
{
    public function __construct(
        public mixed $value,
        public int $version,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
