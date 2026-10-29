<?php

declare(strict_types=1);

namespace App\Domain\Document;

use JsonException;
use RuntimeException;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * The shared contract in `schema/`, loaded from disk and turned into typed specs.
 *
 * The JSON files are the single source of truth for both the editor and this backend, so they are
 * read at runtime (never copied into PHP) and parsed once per process per directory. A malformed
 * schema file is a deployment error, not a user error: it throws instead of degrading.
 */
final class Schema
{
    /**
     * @var array<string, self>
     */
    private static array $loaded = [];

    /**
     * Compiled PCRE patterns keyed by their `config.json → patterns` name.
     *
     * @var array<string, string>
     */
    private readonly array $patterns;

    /**
     * @param list<string> $devices
     * @param array<string, string> $patterns Raw patterns as written in `config.json`.
     * @param array<string, FieldSpec> $settings
     * @param array<string, StyleProperty> $styleProperties In file order (= CSS declaration order).
     * @param array<string, WidgetManifest> $widgets
     * @param array<string, string> $fonts Font key → CSS stack.
     * @param array<string, string> $icons Icon key → trusted inner SVG markup.
     * @param list<string> $fontKeys Style keys a global font preset may set (typography without `globalFont`).
     * @param array<array-key, mixed> $defaultGlobals `globals.json`, used until the site saves its own.
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly array $devices,
        public readonly int $tabletBreakpoint,
        public readonly int $mobileBreakpoint,
        public readonly int $maxDocumentBytes,
        public readonly int $maxNodes,
        public readonly int $maxDepth,
        public readonly int $maxUrlLength,
        array $patterns,
        public readonly array $settings,
        public readonly array $styleProperties,
        public readonly array $widgets,
        public readonly array $fonts,
        public readonly array $icons,
        public readonly string $baseCss,
        public readonly int $maxCustomCssLength = 10000,
        public readonly int $maxGlobalColors = 50,
        public readonly int $maxGlobalFonts = 20,
        public readonly int $maxGlobalNameLength = 60,
        public readonly array $fontKeys = [],
        public readonly array $defaultGlobals = [],
    ) {
        $compiled = [];
        foreach ($patterns as $name => $pattern) {
            // `D`: without it PCRE's `$` also matches before a trailing "\n", which JS's `$` does not.
            $compiled[$name] = '~' . str_replace('~', '\~', $pattern) . '~uD';
        }
        $this->patterns = $compiled;
    }

    public static function fromDirectory(string $directory): self
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException(sprintf('Schema directory "%s" does not exist.', $directory));
        }

        return self::$loaded[$real] ??= self::parse($real);
    }

    /**
     * Whether `$value` matches the named `config.json` pattern.
     */
    public function matches(string $patternName, string $value): bool
    {
        $pattern = $this->patterns[$patternName] ?? '';
        if ($pattern === '') {
            throw new RuntimeException(sprintf('Unknown schema pattern "%s".', $patternName));
        }

        return preg_match($pattern, $value) === 1;
    }

    /**
     * Content hash used in `/assets/cvb-base.{hash}.css`, so the URL can be cached forever.
     */
    public function baseCssHash(): string
    {
        return substr(hash('sha256', $this->baseCss), 0, 16);
    }

    public function widget(string $type): ?WidgetManifest
    {
        return $this->widgets[$type] ?? null;
    }

    private static function parse(string $dir): self
    {
        $config = self::object(self::readJson($dir . '/config.json'), 'config.json');
        $styleJson = self::object(self::readJson($dir . '/style-properties.json'), 'style-properties.json');
        $controls = self::object(self::readJson($dir . '/controls.json'), 'controls.json');
        $fontsJson = self::object(self::readJson($dir . '/fonts.json'), 'fonts.json');
        $iconsJson = self::object(self::readJson($dir . '/icons.json'), 'icons.json');

        $baseCss = file_get_contents($dir . '/base.css');
        if ($baseCss === false) {
            throw new RuntimeException('Cannot read schema/base.css.');
        }

        $breakpoints = self::object($config['breakpoints'] ?? null, 'config.breakpoints');
        $limits = self::object($config['limits'] ?? null, 'config.limits');

        $patterns = [];
        foreach (self::object($config['patterns'] ?? null, 'config.patterns') as $name => $pattern) {
            $patterns[(string) $name] = self::string($pattern, 'config.patterns.' . $name);
        }

        $settings = [];
        foreach (self::object($config['settings'] ?? null, 'config.settings') as $name => $spec) {
            $settings[(string) $name] = self::fieldSpec((string) $name, self::object($spec, 'config.settings.' . $name));
        }

        $styleProperties = [];
        foreach ($styleJson as $key => $spec) {
            $key = (string) $key;
            $spec = self::object($spec, 'style-properties.' . $key);
            $css = $spec['css'] ?? null;
            $styleProperties[$key] = new StyleProperty(
                key: $key,
                type: self::string($spec['type'] ?? null, 'style-properties.' . $key . '.type'),
                css: is_array($css) ? self::stringList($css, $key . '.css') : [self::string($css, $key . '.css')],
                units: self::stringList($spec['units'] ?? [], $key . '.units'),
                options: self::stringList($spec['options'] ?? [], $key . '.options'),
                min: self::numberOrNull($spec['min'] ?? null),
                max: self::numberOrNull($spec['max'] ?? null),
                integer: ($spec['integer'] ?? false) === true,
                hover: ($spec['state'] ?? null) === 'hover',
                corners: ($spec['corners'] ?? false) === true,
            );
        }

        $groups = [];
        foreach (self::object($controls['groups'] ?? [], 'controls.groups') as $group => $keys) {
            $groups[(string) $group] = self::stringList(self::object($keys, 'controls.groups'), 'controls.groups.' . $group);
        }

        $commonKeys = [];
        foreach (self::object($controls['commonAdvanced'] ?? [], 'controls.commonAdvanced') as $section) {
            foreach (self::styleKeysOf(self::object($section, 'controls.commonAdvanced'), $groups) as $key) {
                $commonKeys[$key] = true;
            }
        }

        $widgets = [];
        $files = glob($dir . '/widgets/*.json') ?: [];
        foreach ($files as $file) {
            $manifest = self::object(self::readJson($file), basename($file));
            $type = self::string($manifest['type'] ?? null, basename($file) . '.type');

            $props = [];
            foreach (self::object($manifest['props'] ?? [], $type . '.props') as $name => $spec) {
                $props[(string) $name] = self::fieldSpec((string) $name, self::object($spec, $type . '.props.' . $name));
            }

            $targets = [];
            foreach (self::object($manifest['styleTargets'] ?? [], $type . '.styleTargets') as $key => $target) {
                $targets[(string) $key] = self::string($target, $type . '.styleTargets.' . $key);
            }

            $allowed = $commonKeys;
            $tabs = self::object($manifest['controls'] ?? [], $type . '.controls');
            foreach (['content', 'style'] as $tab) {
                foreach (self::object($tabs[$tab] ?? [], $type . '.controls.' . $tab) as $section) {
                    foreach (self::styleKeysOf(self::object($section, $type . '.controls'), $groups) as $key) {
                        $allowed[$key] = true;
                    }
                }
            }

            $widgets[$type] = new WidgetManifest(
                type: $type,
                acceptsChildren: ($manifest['acceptsChildren'] ?? false) === true,
                props: $props,
                styleTargets: $targets,
                allowedStyleKeys: $allowed,
            );
        }

        $fonts = [];
        foreach ($fontsJson as $key => $font) {
            $fonts[(string) $key] = self::string(self::object($font, 'fonts.' . $key)['stack'] ?? null, 'fonts.' . $key . '.stack');
        }

        $icons = [];
        foreach (self::object($iconsJson['icons'] ?? null, 'icons.icons') as $key => $icon) {
            $icons[(string) $key] = self::string(self::object($icon, 'icons.' . $key)['svg'] ?? null, 'icons.' . $key . '.svg');
        }

        return new self(
            schemaVersion: self::int($config['schemaVersion'] ?? null, 'config.schemaVersion'),
            devices: self::stringList(self::object($config['devices'] ?? null, 'config.devices'), 'config.devices'),
            tabletBreakpoint: self::int($breakpoints['tablet'] ?? null, 'config.breakpoints.tablet'),
            mobileBreakpoint: self::int($breakpoints['mobile'] ?? null, 'config.breakpoints.mobile'),
            maxDocumentBytes: self::int($limits['maxDocumentBytes'] ?? null, 'config.limits.maxDocumentBytes'),
            maxNodes: self::int($limits['maxNodes'] ?? null, 'config.limits.maxNodes'),
            maxDepth: self::int($limits['maxDepth'] ?? null, 'config.limits.maxDepth'),
            maxUrlLength: self::int($limits['maxUrlLength'] ?? null, 'config.limits.maxUrlLength'),
            patterns: $patterns,
            settings: $settings,
            styleProperties: $styleProperties,
            widgets: $widgets,
            fonts: $fonts,
            icons: $icons,
            baseCss: $baseCss,
            maxCustomCssLength: self::int($limits['maxCustomCssLength'] ?? null, 'config.limits.maxCustomCssLength'),
            maxGlobalColors: self::int($limits['maxGlobalColors'] ?? null, 'config.limits.maxGlobalColors'),
            maxGlobalFonts: self::int($limits['maxGlobalFonts'] ?? null, 'config.limits.maxGlobalFonts'),
            maxGlobalNameLength: self::int($limits['maxGlobalNameLength'] ?? null, 'config.limits.maxGlobalNameLength'),
            fontKeys: array_values(array_filter($groups['typography'] ?? [], static fn(string $key): bool => $key !== 'globalFont')),
            defaultGlobals: self::object(self::readJson($dir . '/globals.json'), 'globals.json'),
        );
    }

    /**
     * Style keys referenced by one control section, with `group` entries expanded.
     *
     * @param array<array-key, mixed> $section
     * @param array<string, list<string>> $groups
     *
     * @return list<string>
     */
    private static function styleKeysOf(array $section, array $groups): array
    {
        $keys = [];
        $controls = $section['controls'] ?? [];
        if (!is_array($controls)) {
            return [];
        }

        foreach ($controls as $control) {
            if (!is_array($control)) {
                continue;
            }
            if (isset($control['style']) && is_string($control['style'])) {
                $keys[] = $control['style'];
            }
            if (isset($control['group']) && is_string($control['group'])) {
                foreach ($groups[$control['group']] ?? [] as $key) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $spec
     */
    private static function fieldSpec(string $name, array $spec): FieldSpec
    {
        $maxLength = $spec['maxLength'] ?? null;

        return new FieldSpec(
            name: $name,
            type: self::string($spec['type'] ?? null, $name . '.type'),
            default: array_key_exists('default', $spec) ? $spec['default'] : null,
            maxLength: is_int($maxLength) ? $maxLength : null,
            options: self::stringList($spec['options'] ?? [], $name . '.options'),
            min: self::numberOrNull($spec['min'] ?? null),
            max: self::numberOrNull($spec['max'] ?? null),
            integer: ($spec['integer'] ?? false) === true,
        );
    }

    private static function readJson(string $file): mixed
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Cannot read schema file "%s".', $file));
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Schema file "%s" is not valid JSON.', $file), 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function object(mixed $value, string $where): array
    {
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Schema: "%s" must be an object or array.', $where));
        }

        return $value;
    }

    private static function string(mixed $value, string $where): string
    {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Schema: "%s" must be a string.', $where));
        }

        return $value;
    }

    private static function int(mixed $value, string $where): int
    {
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('Schema: "%s" must be an integer.', $where));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $where): array
    {
        if (!is_array($value)) {
            throw new RuntimeException(sprintf('Schema: "%s" must be a list of strings.', $where));
        }

        $list = [];
        foreach ($value as $item) {
            $list[] = self::string($item, $where);
        }

        return $list;
    }

    private static function numberOrNull(mixed $value): int|float|null
    {
        if (is_bool($value)) {
            return null;
        }

        return is_int($value) || is_float($value) ? $value : null;
    }
}
