<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * One typed field from the shared contract: a widget prop (`widgets/*.json → props`) or a page
 * setting (`config.json → settings`). Both use the same vocabulary of types.
 */
final readonly class FieldSpec
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $name,
        public string $type,
        public mixed $default,
        public ?int $maxLength = null,
        public array $options = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public bool $integer = false,
    ) {}

    /**
     * Settings whose default is `null` (colors, font) may be explicitly unset with `null`.
     */
    public function isNullable(): bool
    {
        return $this->default === null;
    }
}
