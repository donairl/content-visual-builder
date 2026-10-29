<?php

declare(strict_types=1);

namespace App\Domain\Document;

use App\Domain\Render\RichTextSanitizer;

use function is_array;
use function is_string;

/**
 * Sanitizes every `richtext` prop of a document before it is stored (README §6 "SHOULD also
 * sanitize on save").
 *
 * Defense in depth only: rendering sanitizes again, but a stored draft is also loaded back into the
 * editor canvas of *another* user, so it should not carry markup the contract forbids.
 */
final readonly class DocumentSanitizer
{
    public function __construct(
        private Schema $schema,
        private RichTextSanitizer $sanitizer,
    ) {}

    /**
     * @param array<array-key, mixed> $document A validated document.
     *
     * @return array<array-key, mixed>
     */
    public function sanitize(array $document): array
    {
        $nodes = $document['nodes'] ?? null;
        if (!is_array($nodes)) {
            return $document;
        }

        foreach ($nodes as $id => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            $props = $node['props'] ?? null;
            $manifest = is_string($type) ? $this->schema->widget($type) : null;
            if ($manifest === null || !is_array($props)) {
                continue;
            }

            foreach ($props as $name => $value) {
                $spec = $manifest->props[(string) $name] ?? null;
                if ($spec !== null && $spec->type === 'richtext' && is_string($value)) {
                    $props[$name] = $this->sanitizer->sanitize($value);
                }
            }
            $node['props'] = $props;
            $nodes[$id] = $node;
        }

        $document['nodes'] = $nodes;

        return $document;
    }
}
