<?php

declare(strict_types=1);

namespace App\Domain\Document;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Validates single values against the contract's value types (README §2).
 *
 * Shared by {@see DocumentValidator}, which turns a non-null result into an error at the right path,
 * and by the CSS generator and renderers, which silently skip or replace anything that fails — so
 * the output stays safe even for a document that was never validated.
 *
 * Every method returns `null` for a valid value, otherwise a human-readable message.
 */
final readonly class ValueValidator
{
    private const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double', 'groove'];
    private const BACKGROUND_TYPES = ['none', 'color', 'gradient', 'image'];
    private const IMAGE_SIZES = ['auto', 'cover', 'contain'];
    private const IMAGE_POSITIONS = [
        'center center', 'top center', 'bottom center', 'center left', 'center right',
        'top left', 'top right', 'bottom left', 'bottom right',
    ];
    private const IMAGE_REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];
    private const BOX_SIDES = ['top', 'right', 'bottom', 'left'];

    public function __construct(
        private Schema $schema,
    ) {}

    /**
     * JSON numbers only: PHP's int/float, never bool or numeric strings.
     *
     * @psalm-assert-if-true int|float $value
     */
    public static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    /**
     * README §2: `abs(n * 1000 - round(n * 1000)) < 1e-6`. Both sides use the same formula so that
     * binary floating-point noise (`0.1 + 0.2`) cannot make TS and PHP disagree.
     */
    public static function hasAtMostThreeDecimals(int|float $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        $scaled = $value * 1000;

        return abs($scaled - round($scaled)) < 1e-6;
    }

    /**
     * "Integer" means integer-valued: `5` and `5.0` both pass.
     */
    public static function isIntegerValued(int|float $value): bool
    {
        return is_int($value) || floor($value) === $value;
    }

    /**
     * Objects arrive as PHP arrays (`json_decode(..., true)`). `{}` and `[]` both decode to `[]`, so an
     * empty array is accepted as an empty object; a non-empty list is not an object.
     *
     * @psalm-assert-if-true array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @psalm-assert-if-true list<mixed> $value
     */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * The value if it is an object, otherwise an empty one.
     *
     * @return array<array-key, mixed>
     */
    public static function asObject(mixed $value): array
    {
        return self::isObject($value) ? $value : [];
    }

    /**
     * The value if it is a list, otherwise an empty one.
     *
     * @return list<mixed>
     */
    public static function asList(mixed $value): array
    {
        return self::isList($value) ? $value : [];
    }

    /**
     * Length in Unicode code points, as the contract counts it (JS `[...s].length`).
     */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    public function prop(FieldSpec $spec, mixed $value): ?string
    {
        return match ($spec->type) {
            'text', 'richtext' => $this->text($spec, $value),
            'enum' => is_string($value) && in_array($value, $spec->options, true) ? null : 'Must be one of: ' . implode(', ', $spec->options) . '.',
            'bool' => is_bool($value) ? null : 'Must be a boolean.',
            'number' => $this->number($value, $spec->min, $spec->max, $spec->integer),
            'link' => $this->link($value),
            'image' => $this->image($value),
            'videoUrl' => is_string($value) && ($value === '' || $this->schema->matches('videoUrl', $value)) ? null : 'Must be an http(s) URL or empty.',
            'icon' => is_string($value) && isset($this->schema->icons[$value]) ? null : 'Unknown icon.',
            default => 'Unsupported prop type "' . $spec->type . '".',
        };
    }

    public function setting(FieldSpec $spec, mixed $value): ?string
    {
        if ($value === null && $spec->isNullable()) {
            return null;
        }

        return match ($spec->type) {
            'text' => $this->text($spec, $value),
            'number' => $this->number($value, $spec->min, $spec->max, $spec->integer),
            'color' => $this->color($value),
            'fontFamily' => $this->font($value),
            'css' => $this->customCss($value, $spec->maxLength ?? 0),
            default => 'Unsupported setting type "' . $spec->type . '".',
        };
    }

    /**
     * Custom CSS (`settings.customCss`, `advanced.customCss`): a string of at most `$maxLength` code
     * points that passes {@see CustomCss::error()}.
     */
    public function customCss(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return 'Must be a string.';
        }
        if (self::length($value) > $maxLength) {
            return 'Must be at most ' . $maxLength . ' characters.';
        }

        return CustomCss::error($value);
    }

    /**
     * Validates one device value of a style key. `null` ("unset") is handled by the caller.
     */
    public function style(StyleProperty $spec, mixed $value): ?string
    {
        return match ($spec->type) {
            'color' => $this->color($value),
            'size' => $this->size($spec, $value),
            'number' => $this->number($value, $spec->min, $spec->max, $spec->integer),
            'enum' => is_string($value) && in_array($value, $spec->options, true) ? null : 'Must be one of: ' . implode(', ', $spec->options) . '.',
            'fontFamily' => $this->font($value),
            'globalFont' => is_string($value) && $this->schema->matches('globalId', $value) ? null : 'Must be a global font id.',
            'box' => $this->box($spec, $value),
            'border' => $this->border($value),
            'shadow' => $this->shadow($value),
            'background' => $this->background($value),
            default => 'Unsupported style type "' . $spec->type . '".',
        };
    }

    public function cssId(mixed $value): ?string
    {
        return is_string($value) && ($value === '' || $this->schema->matches('cssId', $value))
            ? null
            : 'Must be empty or a valid CSS id (letter first, then letters, digits, "-" or "_").';
    }

    public function cssClasses(mixed $value): ?string
    {
        return is_string($value) && ($value === '' || $this->schema->matches('cssClasses', $value))
            ? null
            : 'Must be empty or up to 10 space-separated CSS class names.';
    }

    public function hideOn(mixed $value): ?string
    {
        if (!self::isList($value)) {
            return 'Must be a list of devices.';
        }

        $seen = [];
        foreach ($value as $device) {
            if (!is_string($device) || !in_array($device, $this->schema->devices, true)) {
                return 'Unknown device.';
            }
            if (isset($seen[$device])) {
                return 'Devices must not repeat.';
            }
            $seen[$device] = true;
        }

        return null;
    }

    /**
     * A color in a document: a literal or a `global:<id>` reference (README §9).
     */
    public function color(mixed $value): ?string
    {
        return is_string($value) && ($this->schema->matches('color', $value) || $this->schema->matches('globalColor', $value))
            ? null
            : 'Must be a hex color (#rgb, #rgba, #rrggbb, #rrggbbaa), "transparent" or a global color.';
    }

    /**
     * A hex color or `transparent`: what global colors themselves hold.
     */
    public function literalColor(mixed $value): ?string
    {
        return is_string($value) && $this->schema->matches('color', $value)
            ? null
            : 'Must be a hex color (#rgb, #rgba, #rrggbb, #rrggbbaa) or "transparent".';
    }

    public function number(mixed $value, int|float|null $min, int|float|null $max, bool $integer = false): ?string
    {
        if (!self::isNumber($value)) {
            return 'Must be a finite number.';
        }
        if ($min !== null && $value < $min) {
            return 'Must be at least ' . $min . '.';
        }
        if ($max !== null && $value > $max) {
            return 'Must be at most ' . $max . '.';
        }
        if ($integer && !self::isIntegerValued($value)) {
            return 'Must be an integer.';
        }
        if (!self::hasAtMostThreeDecimals($value)) {
            return 'At most 3 decimal places are allowed.';
        }

        return null;
    }

    private function text(FieldSpec $spec, mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'Must be a string.';
        }
        if ($spec->maxLength !== null && self::length($value) > $spec->maxLength) {
            return 'Must be at most ' . $spec->maxLength . ' characters.';
        }

        return null;
    }

    private function font(mixed $value): ?string
    {
        return is_string($value) && isset($this->schema->fonts[$value]) ? null : 'Unknown font.';
    }

    private function link(mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a link object.';
        }

        foreach ($value as $key => $item) {
            switch ($key) {
                case 'url':
                    if (!is_string($item)) {
                        return 'The URL must be a string.';
                    }
                    if ($item !== '' && (self::length($item) > $this->schema->maxUrlLength || !$this->schema->matches('linkUrl', $item))) {
                        return 'The URL must be http(s), mailto:, tel:, a site-relative path or an #anchor.';
                    }
                    break;
                case 'newTab':
                case 'nofollow':
                    if (!is_bool($item)) {
                        return '"' . $key . '" must be a boolean.';
                    }
                    break;
                default:
                    return 'Unknown link key "' . $key . '".';
            }
        }

        return null;
    }

    private function image(mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be an image object.';
        }

        foreach ($value as $key => $item) {
            switch ($key) {
                case 'url':
                    if (!is_string($item) || ($item !== '' && !$this->schema->matches('mediaUrl', $item))) {
                        return 'The image URL must be http(s) or a site-relative path without quotes or brackets.';
                    }
                    break;
                case 'width':
                case 'height':
                    if ($item !== null && $this->number($item, 1, 20000, true) !== null) {
                        return '"' . $key . '" must be an integer between 1 and 20000, or null.';
                    }
                    break;
                case 'id':
                    if ($item !== null && (!self::isNumber($item) || $item < 1 || !self::isIntegerValued($item))) {
                        return '"id" must be a positive integer or null.';
                    }
                    break;
                default:
                    return 'Unknown image key "' . $key . '".';
            }
        }

        return null;
    }

    private function size(StyleProperty $spec, mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a {value, unit} object.';
        }
        foreach ($value as $key => $_) {
            if ($key !== 'value' && $key !== 'unit') {
                return 'Unknown size key "' . $key . '".';
            }
        }
        if (!array_key_exists('value', $value) || !array_key_exists('unit', $value)) {
            return 'Both "value" and "unit" are required.';
        }
        if (!is_string($value['unit']) || !in_array($value['unit'], $spec->units, true)) {
            return 'Unit must be one of: ' . implode(', ', $spec->units) . '.';
        }

        return $this->number($value['value'], $spec->min, $spec->max, $spec->integer);
    }

    private function box(StyleProperty $spec, mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a box object.';
        }
        foreach ($value as $key => $_) {
            if ($key !== 'unit' && !in_array($key, self::BOX_SIDES, true)) {
                return 'Unknown box key "' . $key . '".';
            }
        }
        if (!isset($value['unit']) || !is_string($value['unit']) || !in_array($value['unit'], $spec->units, true)) {
            return 'Unit is required and must be one of: ' . implode(', ', $spec->units) . '.';
        }
        foreach (self::BOX_SIDES as $side) {
            $amount = $value[$side] ?? null;
            if ($amount !== null && ($error = $this->number($amount, $spec->min, $spec->max, $spec->integer)) !== null) {
                return '"' . $side . '": ' . $error;
            }
        }

        return null;
    }

    private function border(mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a border object.';
        }
        foreach ($value as $key => $item) {
            if ($item === null) {
                continue;
            }
            $error = match ($key) {
                'style' => is_string($item) && in_array($item, self::BORDER_STYLES, true) ? null : 'Unknown border style.',
                'width' => $this->number($item, 0, 100),
                'color' => $this->color($item),
                default => 'Unknown border key "' . $key . '".',
            };
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function shadow(mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a shadow object.';
        }

        $required = ['x', 'y', 'blur', 'spread', 'color', 'inset'];
        foreach ($value as $key => $_) {
            if (!in_array($key, $required, true)) {
                return 'Unknown shadow key "' . $key . '".';
            }
        }
        if (count($value) !== count($required)) {
            return 'All of x, y, blur, spread, color and inset are required.';
        }

        foreach (['x' => [-200, 200], 'y' => [-200, 200], 'blur' => [0, 300], 'spread' => [-200, 200]] as $key => [$min, $max]) {
            if (($error = $this->number($value[$key], $min, $max)) !== null) {
                return '"' . $key . '": ' . $error;
            }
        }
        if (!is_bool($value['inset'])) {
            return '"inset" must be a boolean.';
        }

        return $this->color($value['color']);
    }

    private function background(mixed $value): ?string
    {
        if (!self::isObject($value)) {
            return 'Must be a background object.';
        }
        if (!isset($value['type']) || !is_string($value['type']) || !in_array($value['type'], self::BACKGROUND_TYPES, true)) {
            return 'Type must be one of: ' . implode(', ', self::BACKGROUND_TYPES) . '.';
        }

        foreach ($value as $key => $item) {
            if ($key === 'type' || $item === null) {
                continue;
            }
            $error = match ($key) {
                'color', 'gradientFrom', 'gradientTo' => $this->color($item),
                'gradientAngle' => $this->number($item, 0, 360),
                'imageUrl' => is_string($item) && ($item === '' || $this->schema->matches('mediaUrl', $item))
                    ? null
                    : 'The image URL must be http(s) or a site-relative path without quotes or brackets.',
                'imageSize' => is_string($item) && in_array($item, self::IMAGE_SIZES, true) ? null : 'Unknown image size.',
                'imagePosition' => is_string($item) && in_array($item, self::IMAGE_POSITIONS, true) ? null : 'Unknown image position.',
                'imageRepeat' => is_string($item) && in_array($item, self::IMAGE_REPEATS, true) ? null : 'Unknown image repeat.',
                default => 'Unknown background key "' . $key . '".',
            };
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }
}
