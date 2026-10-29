<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\Schema;
use App\Domain\Document\ValueValidator;

use function is_string;

/**
 * Builds the standalone public page (README §5 "Public page document").
 *
 * The output references only two stylesheets — the shared base CSS and the page's own generated
 * CSS — and contains no script, so the published page loads nothing from the editor.
 */
final readonly class PublicPageRenderer
{
    public function __construct(
        private Schema $schema,
        private PageRenderer $pageRenderer,
        private CssGenerator $cssGenerator,
    ) {}

    /**
     * Content hash in `/assets/page/{id}.{hash}.css`.
     */
    public static function cssHash(string $css): string
    {
        return substr(hash('sha256', $css), 0, 16);
    }

    public function render(int $pageId, string $title, mixed $document, ?SiteContext $site = null): RenderedPage
    {
        $tree = RenderTree::build($this->schema, $document);
        $css = $this->cssGenerator->generateTree($tree, $site);
        $cssHash = self::cssHash($css);

        $stylesheet = $css === ''
            ? null
            : '/assets/page/' . $pageId . '.' . $cssHash . '.css';

        return new RenderedPage(
            html: $this->document($title, $this->metaDescription($tree), $this->pageRenderer->renderTree($tree, $site), $stylesheet),
            css: $css,
            cssHash: $cssHash,
        );
    }

    /**
     * The same document with the CSS inlined, for previews: a draft has no stored stylesheet URL.
     */
    public function renderPreview(string $title, mixed $document, ?SiteContext $site = null): string
    {
        $tree = RenderTree::build($this->schema, $document);
        $css = $this->cssGenerator->generateTree($tree, $site);

        return $this->document(
            $title,
            $this->metaDescription($tree),
            $this->pageRenderer->renderTree($tree, $site),
            null,
            $css === '' ? null : $css,
        );
    }

    private function document(string $title, string $description, string $body, ?string $stylesheet, ?string $inlineCss = null): string
    {
        $lines = [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . Html::encode($title) . '</title>',
        ];
        if ($description !== '') {
            $lines[] = '<meta name="description"' . Html::attributes(['content' => $description]) . '>';
        }
        $lines[] = '<link rel="stylesheet" href="/assets/cvb-base.' . $this->schema->baseCssHash() . '.css">';
        if ($stylesheet !== null) {
            $lines[] = '<link rel="stylesheet"' . Html::attributes(['href' => $stylesheet]) . '>';
        }
        if ($inlineCss !== null) {
            // Generated CSS only contains validated values; "</" cannot occur in it, but escape the
            // sequence anyway so a future value type cannot close the element early.
            $lines[] = '<style>' . str_replace('</', '<\/', $inlineCss) . '</style>';
        }
        $lines[] = '</head>';
        $lines[] = '<body style="margin:0">';
        $lines[] = '<div class="cvb-root">' . $body . '</div>';
        $lines[] = '</body>';
        $lines[] = '</html>';

        return implode("\n", $lines) . "\n";
    }

    private function metaDescription(RenderTree $tree): string
    {
        $value = $tree->settings['metaDescription'] ?? '';
        $spec = $this->schema->settings['metaDescription'] ?? null;

        if ($spec === null || !is_string($value) || (new ValueValidator($this->schema))->setting($spec, $value) !== null) {
            return '';
        }

        return $value;
    }
}
