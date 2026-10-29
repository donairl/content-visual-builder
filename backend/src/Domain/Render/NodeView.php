<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\WidgetManifest;

use function is_array;
use function is_bool;
use function is_int;
use function is_float;
use function is_string;

/**
 * A node as a renderer sees it: props resolved against the manifest (a missing or invalid prop is
 * replaced by its default) and the root element's `id`/`class` already computed.
 *
 * Resolution happens here so every widget renderer can trust the shapes it reads, even for a
 * document that skipped validation (the parity CLI, a legacy row).
 */
final readonly class NodeView
{
    /**
     * @param array<string, mixed> $props Resolved props, one entry per manifest prop.
     */
    public function __construct(
        public string $id,
        public WidgetManifest $manifest,
        public array $props,
        public string $cssId,
        public string $cssClasses,
    ) {}

    public function string(string $name): string
    {
        $value = $this->props[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    public function bool(string $name): bool
    {
        $value = $this->props[$name] ?? false;

        return is_bool($value) && $value;
    }

    /**
     * @return array{url: string, newTab: bool, nofollow: bool}
     */
    public function link(string $name = 'link'): array
    {
        $value = $this->props[$name] ?? [];
        $value = is_array($value) ? $value : [];
        $url = $value['url'] ?? '';

        return [
            'url' => is_string($url) ? $url : '',
            'newTab' => ($value['newTab'] ?? false) === true,
            'nofollow' => ($value['nofollow'] ?? false) === true,
        ];
    }

    /**
     * @return array{url: string, width: int|null, height: int|null}
     */
    public function image(string $name = 'image'): array
    {
        $value = $this->props[$name] ?? [];
        $value = is_array($value) ? $value : [];
        $width = $value['width'] ?? null;
        $height = $value['height'] ?? null;
        $url = $value['url'] ?? '';

        return [
            'url' => is_string($url) ? $url : '',
            'width' => is_int($width) || is_float($width) ? (int) $width : null,
            'height' => is_int($height) || is_float($height) ? (int) $height : null,
        ];
    }

    /**
     * The root element's `id` and `class` attributes (README §5):
     * `cvb-el cvb-el-{id} cvb-{type}[ modifiers][ cssClasses]`.
     *
     * @param list<string> $modifiers
     */
    public function rootAttributes(array $modifiers = []): string
    {
        $classes = ['cvb-el', 'cvb-el-' . $this->id, 'cvb-' . $this->manifest->type, ...$modifiers];
        if ($this->cssClasses !== '') {
            $classes[] = $this->cssClasses;
        }

        return Html::attributes([
            'id' => $this->cssId !== '' ? $this->cssId : null,
            'class' => implode(' ', $classes),
        ]);
    }

    /**
     * `href`, `target` and `rel` for a link prop with a non-empty URL (README §5).
     *
     * @param array{url: string, newTab: bool, nofollow: bool} $link
     */
    public static function linkAttributes(array $link): string
    {
        $rel = [];
        if ($link['nofollow']) {
            $rel[] = 'nofollow';
        }
        if ($link['newTab']) {
            $rel[] = 'noopener noreferrer';
        }

        return Html::attributes([
            'href' => $link['url'],
            'target' => $link['newTab'] ? '_blank' : null,
            'rel' => $rel === [] ? null : implode(' ', $rel),
        ]);
    }
}
