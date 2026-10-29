<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;
use App\Domain\Render\RichTextSanitizer;

final readonly class TextRenderer implements WidgetRenderer
{
    public function __construct(
        private RichTextSanitizer $sanitizer,
    ) {}

    public function render(NodeView $node, string $children): string
    {
        // Sanitized at render time even though drafts are sanitized on save: the stored document is
        // never trusted as HTML.
        return '<div' . $node->rootAttributes() . '>' . $this->sanitizer->sanitize($node->string('html')) . '</div>';
    }
}
