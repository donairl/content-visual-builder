<?php

declare(strict_types=1);

namespace App\Domain\Document;

/**
 * The parts of a `widgets/*.json` manifest the backend needs: typed props with their defaults, the
 * style keys a node of this type may carry, and where each key applies (`styleTargets`).
 */
final readonly class WidgetManifest
{
    /**
     * @param array<string, FieldSpec> $props
     * @param array<string, string> $styleTargets
     * @param array<string, true> $allowedStyleKeys
     */
    public function __construct(
        public string $type,
        public bool $acceptsChildren,
        public array $props,
        public array $styleTargets,
        public array $allowedStyleKeys,
    ) {}

    public function allowsStyle(string $key): bool
    {
        return isset($this->allowedStyleKeys[$key]);
    }

    public function styleTarget(string $key): string
    {
        return $this->styleTargets[$key] ?? '';
    }
}
