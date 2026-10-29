<?php

declare(strict_types=1);

namespace App\Domain\Render\Widget;

use App\Domain\Render\Html;
use App\Domain\Render\NodeView;

final readonly class IconRenderer implements WidgetRenderer
{
    private const SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

    /**
     * @param array<string, string> $icons Icon key → trusted inner SVG markup from `icons.json`.
     */
    public function __construct(
        private array $icons,
    ) {}

    public function render(NodeView $node, string $children): string
    {
        // `icons.json` is part of the deployed contract, not user input, so its markup is trusted.
        $svg = self::SVG_OPEN . ($this->icons[$node->string('icon')] ?? '') . '</svg>';
        $label = $node->string('label');
        $link = $node->link();

        if ($link['url'] !== '') {
            // The link carries the accessible name; the span stays neutral.
            $aria = $label !== '' ? Html::attributes(['aria-label' => $label]) : '';

            return '<span' . $node->rootAttributes() . '><a' . NodeView::linkAttributes($link) . $aria . '>' . $svg . '</a></span>';
        }

        $a11y = $label !== ''
            ? Html::attributes(['role' => 'img', 'aria-label' => $label])
            : ' aria-hidden="true"';

        return '<span' . $node->rootAttributes() . $a11y . '>' . $svg . '</span>';
    }
}
