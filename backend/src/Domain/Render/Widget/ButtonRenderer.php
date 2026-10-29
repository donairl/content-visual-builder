<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\Html;
use App\Domain\Render\NodeView;

final readonly class ButtonRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        $attributes = $node->rootAttributes(['cvb-button--' . $node->string('size')]);
        $text = Html::encode($node->string('text'));
        $link = $node->link();

        // Without a URL the button is decorative: a span, so it is not a dead link for keyboards.
        if ($link['url'] === '') {
            return '<span' . $attributes . '>' . $text . '</span>';
        }

        return '<a' . $attributes . NodeView::linkAttributes($link) . '>' . $text . '</a>';
    }
}
