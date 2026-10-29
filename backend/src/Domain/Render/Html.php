<?php

declare(strict_types=1);

namespace App\Domain\Render;

/**
 * Minimal HTML building blocks for the renderers.
 *
 * Kept local rather than using yiisoft/html's tag objects because the markup is fixed by the
 * contract (README §5) down to which attributes exist, and string building keeps that one-to-one
 * with the spec table.
 */
final class Html
{
    public static function encode(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Renders ` name="value"` pairs in the given order. `true` renders a bare boolean attribute;
     * `null` and `false` omit the attribute.
     *
     * @param array<string, string|bool|null> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $html .= $value === true ? ' ' . $name : ' ' . $name . '="' . self::encode($value) . '"';
        }

        return $html;
    }
}
