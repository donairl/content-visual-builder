<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;

/**
 * A reusable component instance (README §10): a wrapper around the component's rendered nodes,
 * which the page renderer passes in as `$children`.
 */
final readonly class ComponentRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        return '<div' . $node->rootAttributes() . '>' . $children . '</div>';
    }
}
