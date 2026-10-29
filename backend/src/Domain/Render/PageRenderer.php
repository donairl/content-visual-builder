<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\Schema;
use App\Domain\Document\ValueValidator;
use App\Domain\Document\WidgetManifest;
use App\Domain\Render\Widget\ButtonRenderer;
use App\Domain\Render\Widget\ComponentRenderer;
use App\Domain\Render\Widget\ContainerRenderer;
use App\Domain\Render\Widget\DividerRenderer;
use App\Domain\Render\Widget\HeadingRenderer;
use App\Domain\Render\Widget\IconRenderer;
use App\Domain\Render\Widget\ImageRenderer;
use App\Domain\Render\Widget\SpacerRenderer;
use App\Domain\Render\Widget\TextRenderer;
use App\Domain\Render\Widget\VideoRenderer;
use App\Domain\Render\Widget\WidgetRenderer;

use function array_key_exists;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Renders a document's nodes to the HTML that goes inside `<div class="cvb-root">` (README §5).
 *
 * Children are concatenated without whitespace; parity with the editor is checked on the parsed
 * DOM, so attribute order and entity style are free.
 */
final readonly class PageRenderer
{
    /**
     * @var array<string, WidgetRenderer>
     */
    private array $renderers;

    private ValueValidator $values;

    public function __construct(
        private Schema $schema,
        RichTextSanitizer $sanitizer,
    ) {
        $this->values = new ValueValidator($schema);
        $this->renderers = [
            'container' => new ContainerRenderer(),
            'heading' => new HeadingRenderer(),
            'text' => new TextRenderer($sanitizer),
            'button' => new ButtonRenderer(),
            'image' => new ImageRenderer(),
            'video' => new VideoRenderer(),
            'spacer' => new SpacerRenderer(),
            'divider' => new DividerRenderer(),
            'icon' => new IconRenderer($schema->icons),
            'component' => new ComponentRenderer(),
        ];
    }

    /**
     * @param SiteContext|null $site Supplies component documents (README §10); without it every
     *                               component instance is empty.
     */
    public function render(mixed $document, ?SiteContext $site = null): string
    {
        return $this->renderTree(RenderTree::build($this->schema, $document), $site);
    }

    public function renderTree(RenderTree $tree, ?SiteContext $site = null): string
    {
        return $this->renderRoots($tree, $site ?? SiteContext::empty(), false);
    }

    /**
     * @param bool $inComponent Rendering a component's own nodes: CSS ids are omitted (a component
     *                          may appear several times on a page) and nested instances render
     *                          nothing.
     */
    private function renderRoots(RenderTree $tree, SiteContext $site, bool $inComponent): string
    {
        $html = '';
        foreach ($tree->roots as $id) {
            $html .= $this->renderNode($tree, $id, $site, $inComponent);
        }

        return $html;
    }

    private function renderNode(RenderTree $tree, string $id, SiteContext $site, bool $inComponent): string
    {
        $entry = $tree->nodes[$id];
        $manifest = $entry['manifest'];
        $renderer = $this->renderers[$manifest->type] ?? null;
        if (!$renderer instanceof WidgetRenderer) {
            return '';
        }

        $view = $this->view($id, $entry['node'], $manifest, $inComponent);

        if ($manifest->type === 'component') {
            if ($inComponent) {
                return '';
            }
            $componentId = $view->props['componentId'] ?? null;
            $document = is_int($componentId) || is_float($componentId) ? $site->component((int) $componentId) : null;
            $content = $document === null ? '' : $this->renderRoots(RenderTree::build($this->schema, $document), $site, true);

            return $renderer->render($view, $content);
        }

        $children = '';
        foreach ($entry['children'] as $childId) {
            $children .= $this->renderNode($tree, $childId, $site, $inComponent);
        }

        return $renderer->render($view, $children);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function view(string $id, array $node, WidgetManifest $manifest, bool $inComponent = false): NodeView
    {
        $rawProps = ValueValidator::asObject($node['props'] ?? null);
        $props = [];

        foreach ($manifest->props as $name => $spec) {
            // README §1: a missing prop falls back to the manifest default at render time. An invalid
            // one does too, so the output never depends on unvalidated data.
            if (array_key_exists($name, $rawProps) && $this->values->prop($spec, $rawProps[$name]) === null) {
                $value = $rawProps[$name];
                // Link/image keys are optional; fill the gaps from the default object.
                $props[$name] = is_array($value) && is_array($spec->default) ? $value + $spec->default : $value;
            } else {
                $props[$name] = $spec->default;
            }
        }

        $advanced = ValueValidator::asObject($node['advanced'] ?? null);
        $cssId = $advanced['cssId'] ?? '';
        $cssClasses = $advanced['cssClasses'] ?? '';

        return new NodeView(
            id: $id,
            manifest: $manifest,
            props: $props,
            cssId: !$inComponent && is_string($cssId) && $this->values->cssId($cssId) === null ? $cssId : '',
            cssClasses: is_string($cssClasses) && $this->values->cssClasses($cssClasses) === null ? $cssClasses : '',
        );
    }
}
