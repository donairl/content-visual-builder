<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\Html;
use App\Domain\Render\NodeView;

final readonly class HeadingRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        $tag = $node->string('tag');
        $text = Html::encode($node->string('text'));
        $link = $node->link();

        if ($link['url'] !== '') {
            $text = '<a' . NodeView::linkAttributes($link) . '>' . $text . '</a>';
        }

        return '<' . $tag . $node->rootAttributes() . '>' . $text . '</' . $tag . '>';
    }
}
