<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\Html;
use App\Domain\Render\NodeView;

final readonly class ImageRenderer implements WidgetRenderer
{
    public function render(NodeView $node, string $children): string
    {
        $image = $node->image();
        $html = '<figure' . $node->rootAttributes() . '>';

        if ($image['url'] !== '') {
            $bothSizes = $image['width'] !== null && $image['height'] !== null;
            $img = '<img' . Html::attributes([
                'src' => $image['url'],
                // Always present: an empty alt marks the image as decorative instead of letting
                // screen readers announce the file name.
                'alt' => $node->string('alt'),
                'loading' => 'lazy',
                'decoding' => 'async',
                'width' => $bothSizes ? (string) $image['width'] : null,
                'height' => $bothSizes ? (string) $image['height'] : null,
            ]) . '>';

            $link = $node->link();
            $html .= $link['url'] !== '' ? '<a' . NodeView::linkAttributes($link) . '>' . $img . '</a>' : $img;
        }

        $caption = $node->string('caption');
        if ($caption !== '') {
            $html .= '<figcaption class="cvb-image__caption">' . Html::encode($caption) . '</figcaption>';
        }

        return $html . '</figure>';
    }
}
