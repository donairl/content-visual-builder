<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;

final readonly class DividerRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        return '<hr' . $node->rootAttributes() . '>';
    }
}
