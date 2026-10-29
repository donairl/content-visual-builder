<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * One entry of `style-properties.json`. The order of these objects in {@see Schema::$styleProperties}
 * is the CSS declaration order, so it must never be re-sorted.
 */
final readonly class StyleProperty
{
    /**
     * @param list<string> $css CSS property names; `size` may target several (icon width + height).
     * @param list<string> $units
     * @param list<string> $options
     */
    public function __construct(
        public string $key,
        public string $type,
        public array $css,
        public array $units = [],
        public array $options = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public bool $integer = false,
        public bool $hover = false,
        public bool $corners = false,
    ) {}
}
