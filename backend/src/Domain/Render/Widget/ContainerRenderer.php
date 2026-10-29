<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\NodeView;

final readonly class ContainerRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        // `tag` is an enum prop, already checked against the manifest's options by NodeView.
        $tag = $node->string('tag');

        return '<' . $tag . $node->rootAttributes(['cvb-container--' . $node->string('contentWidth')]) . '>'
            . '<div class="cvb-container__inner">' . $children . '</div>'
            . '</' . $tag . '>';
    }
}
