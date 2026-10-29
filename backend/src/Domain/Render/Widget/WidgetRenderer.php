<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;

/**
 * Renders one widget type to HTML. `$children` is the already-rendered markup of the node's
 * children (only containers receive anything but an empty string).
 */
interface WidgetRenderer
{
    public function render(NodeView $node, string $children): string;
}
