<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\Schema;
use App\Domain\Document\StyleProperty;
use App\Domain\Document\ValueValidator;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Generates the per-page CSS in publish ("media") mode, byte-for-byte as specified in README §4 —
 * the editor produces the same bytes, and `fixtures/documents/*.expected.css` hold them.
 *
 * Every value is re-validated and skipped when invalid, so the generator is safe on its own: no
 * declaration is ever built from a string the contract does not allow.
 */
final readonly class CssGenerator
{
    private const BOX_SIDES = ['top', 'right', 'bottom', 'left'];
    private const CORNERS = [
        'top' => 'border-top-left-radius',
        'right' => 'border-top-right-radius',
        'bottom' => 'border-bottom-right-radius',
        'left' => 'border-bottom-left-radius',
    ];

    private ValueValidator $values;

    public function __construct(
        private Schema $schema,
    ) {
        $this->values = new ValueValidator($schema);
    }

    /**
     * README §4 `fmt(n)`: integer-valued numbers print as integers, anything else with up to three
     * decimals and no trailing zeros (`1.5`, `0.125`, `-0.5`).
     */
    public static function fmt(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (floor($value) === $value) {
            return (string) (int) $value;
        }

        $formatted = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    public function generate(mixed $document, ?SiteContext $site = null): string
    {
        return $this->generateTree(RenderTree::build($this->schema, $document), $site);
    }

    /**
     * @param SiteContext|null $site Resolved global styles; without it every global reference is
     *                               unresolved (and skipped).
     */
    public function generateTree(RenderTree $tree, ?SiteContext $site = null): string
    {
        $site ??= SiteContext::empty();
        /** @var array<string, array<string, list<string>>> $buckets device → selector → declarations */
        $buckets = [];
        foreach ($this->schema->devices as $device) {
            $buckets[$device] = [];
        }
        /** @var array<string, list<string>> $hidden device → node ids */
        $hidden = [];
        // Custom CSS goes last so it can override generated rules (README §4).
        /** @var list<string> $custom */
        $custom = [];
        $pageCss = $tree->settings['customCss'] ?? null;
        $pageCssSpec = $this->schema->settings['customCss'] ?? null;
        if (is_string($pageCss) && $pageCss !== '' && $pageCssSpec !== null && $this->values->setting($pageCssSpec, $pageCss) === null) {
            $custom[] = '.cvb-root{' . $pageCss . '}';
        }

        foreach ($this->withComponents($tree, $site) as [$id, $entry]) {
            $manifest = $entry['manifest'];
            $style = ValueValidator::asObject($entry['node']['style'] ?? null);
            // README §9: the desktop value of `globalFont` picks a preset whose typography fills
            // every device value the node leaves unset.
            $preset = $manifest->allowsStyle('globalFont')
                ? $site->font(ValueValidator::asObject($style['globalFont'] ?? null)['desktop'] ?? null)
                : null;

            foreach ($this->schema->styleProperties as $key => $spec) {
                $responsive = ValueValidator::asObject($style[$key] ?? null);
                if ($preset !== null && in_array($key, $this->schema->fontKeys, true)) {
                    $responsive = $this->withPreset($responsive, $preset[$key] ?? null);
                }
                if ($responsive === [] || !$manifest->allowsStyle($key)) {
                    continue;
                }
                $selector = '.cvb-el-' . $id . ($spec->hover ? ':hover' : '') . $manifest->styleTarget($key);

                foreach ($this->schema->devices as $device) {
                    $value = $responsive[$device] ?? null;
                    if ($value === null || $this->values->style($spec, $value) !== null) {
                        continue;
                    }
                    $declarations = $this->declarations($spec, $value, $site);
                    if ($declarations === []) {
                        continue;
                    }
                    foreach ($declarations as $declaration) {
                        $buckets[$device][$selector][] = $declaration;
                    }
                }
            }

            $advanced = ValueValidator::asObject($entry['node']['advanced'] ?? null);
            $hideOn = $advanced['hideOn'] ?? [];
            if ($this->values->hideOn($hideOn) === null && is_array($hideOn)) {
                foreach ($hideOn as $device) {
                    $hidden[(string) $device][] = $id;
                }
            }
            $nodeCss = $advanced['customCss'] ?? null;
            if (is_string($nodeCss) && $nodeCss !== '' && $this->values->customCss($nodeCss, $this->schema->maxCustomCssLength) === null) {
                $custom[] = '.cvb-el-' . $id . '{' . $nodeCss . '}';
            }
        }

        $lines = [];

        $settingsRule = $this->settingsDeclarations($tree->settings, $site);
        if ($settingsRule !== []) {
            $lines[] = '.cvb-root{' . implode(';', $settingsRule) . '}';
        }

        $tablet = $this->schema->tabletBreakpoint;
        $mobile = $this->schema->mobileBreakpoint;

        self::appendRules($lines, $buckets['desktop'] ?? []);
        self::appendBlock($lines, sprintf('@media (max-width:%dpx){', $tablet), self::rules($buckets['tablet'] ?? []));
        self::appendBlock($lines, sprintf('@media (max-width:%dpx){', $mobile), self::rules($buckets['mobile'] ?? []));

        self::appendBlock($lines, sprintf('@media (min-width:%dpx){', $tablet + 1), self::hideRules($hidden['desktop'] ?? []));
        self::appendBlock(
            $lines,
            sprintf('@media (min-width:%dpx) and (max-width:%dpx){', $mobile + 1, $tablet),
            self::hideRules($hidden['tablet'] ?? []),
        );
        self::appendBlock($lines, sprintf('@media (max-width:%dpx){', $mobile), self::hideRules($hidden['mobile'] ?? []));

        foreach ($custom as $rule) {
            $lines[] = $rule;
        }

        return implode("\n", $lines);
    }

    /**
     * The tree's nodes in order, each component instance followed by its component's nodes the
     * first time that component appears (README §10). Nodes inside a component never expand
     * further components.
     *
     * @return list<array{string, TreeNode}>
     *
     * @psalm-import-type TreeNode from RenderTree
     */
    private function withComponents(RenderTree $tree, SiteContext $site): array
    {
        $values = $this->values;
        $nodes = [];
        $expanded = [];
        foreach ($tree->order as $id) {
            $entry = $tree->nodes[$id];
            $nodes[] = [$id, $entry];
            if ($entry['manifest']->type !== 'component') {
                continue;
            }

            $spec = $entry['manifest']->props['componentId'] ?? null;
            $componentId = ValueValidator::asObject($entry['node']['props'] ?? null)['componentId'] ?? null;
            if ($spec === null || $values->prop($spec, $componentId) !== null || isset($expanded[(int) $componentId])) {
                continue;
            }
            /** @var int|float $componentId */
            $document = $site->component((int) $componentId);
            if ($document === null) {
                continue;
            }
            $expanded[(int) $componentId] = true;

            $inner = RenderTree::build($this->schema, $document);
            foreach ($inner->order as $innerId) {
                if ($inner->nodes[$innerId]['manifest']->type !== 'component') {
                    $nodes[] = [$innerId, $inner->nodes[$innerId]];
                }
            }
        }

        return $nodes;
    }

    /**
     * Declarations for one valid device value, in the order README §2 lists them. Global color
     * references resolve against `$site`; an unresolvable one counts as unset.
     *
     * @return list<string>
     */
    public function declarations(StyleProperty $spec, mixed $value, ?SiteContext $site = null): array
    {
        $site ??= SiteContext::empty();
        $css = $spec->css;

        switch ($spec->type) {
            case 'color':
                $color = $site->resolveColor($this->schema, $value);

                return $color !== null ? self::each($css, $color) : [];

            case 'enum':
                return is_string($value) ? self::each($css, $value) : [];

            case 'globalFont':
                return [];

            case 'number':
                return is_int($value) || is_float($value) ? self::each($css, self::fmt($value)) : [];

            case 'fontFamily':
                return is_string($value) && isset($this->schema->fonts[$value]) ? self::each($css, $this->schema->fonts[$value]) : [];

            case 'size':
                $value = ValueValidator::asObject($value);
                $amount = $value['value'] ?? null;
                $unit = $value['unit'] ?? null;
                if (!self::isNumber($amount) || !is_string($unit)) {
                    return [];
                }

                return self::each($css, self::fmt($amount) . $unit);

            case 'box':
                $value = ValueValidator::asObject($value);
                $unit = $value['unit'] ?? null;
                if (!is_string($unit)) {
                    return [];
                }
                $declarations = [];
                foreach (self::BOX_SIDES as $side) {
                    $amount = $value[$side] ?? null;
                    if (!self::isNumber($amount)) {
                        continue;
                    }
                    $property = $spec->corners ? self::CORNERS[$side] : $css[0] . '-' . $side;
                    $declarations[] = $property . ':' . self::fmt($amount) . $unit;
                }

                return $declarations;

            case 'border':
                $value = ValueValidator::asObject($value);
                $style = $value['style'] ?? null;
                $width = $value['width'] ?? null;
                $color = $site->resolveColor($this->schema, $value['color'] ?? null);
                $declarations = [];
                if (is_string($style)) {
                    $declarations[] = $css[0] . '-style:' . $style;
                }
                if (self::isNumber($width)) {
                    $declarations[] = $css[0] . '-width:' . self::fmt($width) . 'px';
                }
                if (is_string($color)) {
                    $declarations[] = $css[0] . '-color:' . $color;
                }

                return $declarations;

            case 'shadow':
                $value = ValueValidator::asObject($value);
                $parts = [];
                foreach (['x', 'y', 'blur', 'spread'] as $component) {
                    $amount = $value[$component] ?? null;
                    $parts[] = (self::isNumber($amount) ? self::fmt($amount) : '0') . 'px';
                }
                $color = $site->resolveColor($this->schema, $value['color'] ?? null);
                if ($color === null) {
                    return [];
                }

                return [
                    $css[0] . ':' . (($value['inset'] ?? false) === true ? 'inset ' : '')
                    . implode(' ', $parts) . ' ' . $color,
                ];

            case 'background':
                return $this->background(ValueValidator::asObject($value), $site);
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<string>
     */
    private function background(array $value, SiteContext $site): array
    {
        // README §2: "set" means present, non-null and non-empty.
        $get = static function (string $key) use ($value): ?string {
            $item = $value[$key] ?? null;

            return is_string($item) && $item !== '' ? $item : null;
        };
        // Colors additionally have to resolve (README §9).
        $color = fn(string $key): ?string => $get($key) === null ? null : $site->resolveColor($this->schema, $get($key));

        switch ($value['type'] ?? null) {
            case 'none':
                return ['background:none'];

            case 'color':
                $background = $color('color');

                return $background !== null ? ['background-color:' . $background] : [];

            case 'gradient':
                $from = $color('gradientFrom');
                $to = $color('gradientTo');
                if ($from === null || $to === null) {
                    return [];
                }
                $angle = $value['gradientAngle'] ?? null;

                return [sprintf(
                    'background-image:linear-gradient(%sdeg,%s,%s)',
                    self::isNumber($angle) ? self::fmt($angle) : '180',
                    $from,
                    $to,
                )];

            case 'image':
                $declarations = [];
                $background = $color('color');
                if ($background !== null) {
                    $declarations[] = 'background-color:' . $background;
                }
                $url = $get('imageUrl');
                if ($url !== null) {
                    // Safe inside url("..."): patterns.mediaUrl excludes quotes, backslashes, brackets
                    // and whitespace.
                    $declarations[] = 'background-image:url("' . $url . '")';
                    $declarations[] = 'background-size:' . ($get('imageSize') ?? 'cover');
                    $declarations[] = 'background-position:' . ($get('imagePosition') ?? 'center center');
                    $declarations[] = 'background-repeat:' . ($get('imageRepeat') ?? 'no-repeat');
                }

                return $declarations;
        }

        return [];
    }

    /**
     * `.cvb-root` declarations: content width, background, text color, font — each when set. Missing
     * or invalid settings fall back to their `config.json` default.
     *
     * @param array<array-key, mixed> $settings
     *
     * @return list<string>
     */
    private function settingsDeclarations(array $settings, SiteContext $site): array
    {
        $resolve = function (string $key) use ($settings): mixed {
            $spec = $this->schema->settings[$key] ?? null;
            if ($spec === null) {
                return null;
            }
            if (array_key_exists($key, $settings) && $this->values->setting($spec, $settings[$key]) === null) {
                return $settings[$key];
            }

            return $spec->default;
        };

        $declarations = [];

        $width = $resolve('contentWidth');
        if (self::isNumber($width)) {
            $declarations[] = '--cvb-content-width:' . self::fmt($width) . 'px';
        }
        $background = $site->resolveColor($this->schema, $resolve('backgroundColor'));
        if ($background !== null) {
            $declarations[] = 'background-color:' . $background;
        }
        $text = $site->resolveColor($this->schema, $resolve('textColor'));
        if ($text !== null) {
            $declarations[] = 'color:' . $text;
        }
        $font = $resolve('fontFamily');
        if (is_string($font) && isset($this->schema->fonts[$font])) {
            $declarations[] = 'font-family:' . $this->schema->fonts[$font];
        }

        return $declarations;
    }

    /**
     * Per device, the node's own value, or the font preset's where the node leaves it unset.
     *
     * @param array<array-key, mixed> $own
     *
     * @return array<array-key, mixed>
     */
    private function withPreset(array $own, mixed $preset): array
    {
        if (!ValueValidator::isObject($preset)) {
            return $own;
        }

        $merged = [];
        foreach ($this->schema->devices as $device) {
            $value = $own[$device] ?? $preset[$device] ?? null;
            if ($value !== null) {
                $merged[$device] = $value;
            }
        }

        return $merged;
    }

    /**
     * @psalm-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return !is_bool($value) && ValueValidator::isNumber($value);
    }

    /**
     * @param list<string> $properties
     *
     * @return list<string>
     */
    private static function each(array $properties, string $value): array
    {
        return array_map(static fn(string $property): string => $property . ':' . $value, $properties);
    }

    /**
     * @param array<string, list<string>> $rules
     *
     * @return list<string>
     */
    private static function rules(array $rules): array
    {
        $lines = [];
        foreach ($rules as $selector => $declarations) {
            $lines[] = $selector . '{' . implode(';', $declarations) . '}';
        }

        return $lines;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function hideRules(array $ids): array
    {
        return array_map(static fn(string $id): string => '.cvb-el-' . $id . '{display:none}', $ids);
    }

    /**
     * @param list<string> $lines
     * @param array<string, list<string>> $rules
     */
    private static function appendRules(array &$lines, array $rules): void
    {
        foreach (self::rules($rules) as $line) {
            $lines[] = $line;
        }
    }

    /**
     * Appends an at-rule block; empty blocks are omitted entirely.
     *
     * @param list<string> $lines
     * @param list<string> $rules
     */
    private static function appendBlock(array &$lines, string $open, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $lines[] = $open;
        foreach ($rules as $rule) {
            $lines[] = $rule;
        }
        $lines[] = '}';
    }
}
