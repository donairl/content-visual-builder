<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;
use App\Domain\Render\VideoEmbed;

final readonly class VideoRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        return '<div' . $node->rootAttributes() . '>' . VideoEmbed::player(
            $node->string('url'),
            $node->string('title'),
            $node->bool('autoplay'),
            $node->bool('muted'),
            $node->bool('loop'),
            $node->bool('controls'),
        ) . '</div>';
    }
}
