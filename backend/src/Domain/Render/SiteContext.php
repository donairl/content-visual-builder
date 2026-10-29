<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\GlobalsValidator;
use App\Domain\Document\Schema;
use App\Domain\Document\ValueValidator;

use function array_slice;
use function is_string;
use function strlen;
use function substr;

/**
 * Site-wide data a page renders against: the resolved global colors and font presets (README §9)
 * and the published documents of reusable components (README §10). Mirrors the editor's
 * `siteContext()`.
 *
 * Built from the raw stored globals without trusting them: an entry that fails validation is
 * skipped, as is a later entry repeating an id, so rendering never depends on unvalidated data.
 */
final readonly class SiteContext
{
    private const COLOR_PREFIX = 'global:';

    /**
     * @param array<string, string> $colors Global color id → literal color.
     * @param array<string, array<array-key, mixed>> $fonts Global font id → validated preset style.
     * @param array<int, mixed> $components Component id → its published document.
     */
    public function __construct(
        public array $colors = [],
        public array $fonts = [],
        public array $components = [],
    ) {}

    /**
     * @param array<int, mixed> $components Component id → its published document.
     */
    public function withComponents(array $components): self
    {
        return new self($this->colors, $this->fonts, $components);
    }

    /**
     * The published document of component `$id`, if known.
     */
    public function component(int $id): mixed
    {
        return $this->components[$id] ?? null;
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function fromGlobals(Schema $schema, mixed $globals): self
    {
        $globals = ValueValidator::asObject($globals);
        $validator = new GlobalsValidator($schema);

        $colors = [];
        foreach (array_slice(ValueValidator::asList($globals['colors'] ?? null), 0, $schema->maxGlobalColors) as $entry) {
            if ($validator->validate(['colors' => [$entry], 'fonts' => []]) !== []) {
                continue;
            }
            /** @var array{id: string, value: string} $entry */
            $colors[$entry['id']] ??= $entry['value'];
        }

        $fonts = [];
        foreach (array_slice(ValueValidator::asList($globals['fonts'] ?? null), 0, $schema->maxGlobalFonts) as $entry) {
            if ($validator->validate(['colors' => [], 'fonts' => [$entry]]) !== []) {
                continue;
            }
            /** @var array{id: string, style: array<array-key, mixed>} $entry */
            $fonts[$entry['id']] ??= $entry['style'];
        }

        return new self($colors, $fonts);
    }

    /**
     * A literal color for a document color value, or `null` when it cannot be resolved (a reference
     * to a global that does not exist, or anything that is not a color).
     */
    public function resolveColor(Schema $schema, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        if ($schema->matches('globalColor', $value)) {
            return $this->colors[substr($value, strlen(self::COLOR_PREFIX))] ?? null;
        }

        return $schema->matches('color', $value) ? $value : null;
    }

    /**
     * Style of the font preset `$id`, if it exists.
     *
     * @return array<array-key, mixed>|null
     */
    public function font(mixed $id): ?array
    {
        return is_string($id) ? ($this->fonts[$id] ?? null) : null;
    }
}
