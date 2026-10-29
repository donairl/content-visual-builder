<?php

declare(strict_types=1);

namespace App\Domain\Render;

/**
 * What publishing stores: the complete public HTML document and its stylesheet.
 */
final readonly class RenderedPage
{
    public function __construct(
        public string $html,
        public string $css,
        public string $cssHash,
    ) {}
}
