<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Render;

use App\Domain\Render\CssGenerator;
use App\Domain\Render\PageRenderer;
use App\Domain\Render\PublicPageRenderer;
use App\Domain\Render\RichTextSanitizer;
use App\Tests\Support\Contract;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class PageRendererTest extends Unit
{
    #[DataProvider('documents')]
    public function testHtmlIsDomEqualToFixture(string $name): void
    {
        $html = self::renderer()->render(Contract::json('fixtures/documents/' . $name . '.json'), Contract::site($name));

        assertSame(
            Contract::canonicalDom(Contract::read('fixtures/documents/' . $name . '.expected.html')),
            Contract::canonicalDom($html),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'landing' => ['landing'];
        yield 'styles' => ['styles'];
        yield 'edge' => ['edge'];
        yield 'custom-css' => ['custom-css'];
        yield 'globals' => ['globals'];
        yield 'components' => ['components'];
    }

    public function testCanonicalFormIsStrict(): void
    {
        // Guard against a comparison that would pass anything.
        assertSame(Contract::canonicalDom('<p class="a" id="b">x</p>'), Contract::canonicalDom("<p id='b' class=\"a\">x</p>\n"));
        assertStringNotContainsString(Contract::canonicalDom('<p>x</p>'), Contract::canonicalDom('<p>y</p>'));
    }

    /**
     * Invalid props fall back to the manifest default instead of reaching the markup.
     */
    public function testInvalidPropsFallBackToDefaults(): void
    {
        $document = Contract::json('fixtures/documents/edge.json');
        $document['nodes']['head0003']['props'] = ['tag' => 'script', 'text' => ['x'], 'link' => ['url' => 'javascript:alert(1)']];
        $document['nodes']['head0003']['advanced'] = ['cssId' => '"><x', 'cssClasses' => 'a"b', 'hideOn' => []];

        $html = self::renderer()->render($document);

        assertStringContainsString('<h2 class="cvb-el cvb-el-head0003 cvb-heading">Add Your Heading Text Here</h2>', $html);
        assertStringNotContainsString('javascript', $html);
    }

    public function testCyclesAndSharedChildrenRenderOnce(): void
    {
        $document = Contract::json('fixtures/documents/edge.json');
        $document['nodes']['edge0001']['children'][] = 'edge0001';
        $document['rootIds'][] = 'head0003';

        $html = self::renderer()->render($document);

        assertSame(1, substr_count($html, 'cvb-el-head0003'));
        assertSame(1, substr_count($html, 'cvb-el-edge0001'));
    }

    public function testPublicDocumentShape(): void
    {
        $schema = Contract::schema();
        $renderer = new PublicPageRenderer($schema, self::renderer(), new CssGenerator($schema));

        $page = $renderer->render(7, 'Hello & welcome', Contract::json('fixtures/documents/landing.json'));

        assertSame(Contract::read('fixtures/documents/landing.expected.css'), $page->css);
        assertStringContainsString("<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">", $page->html);
        assertStringContainsString('<title>Hello &amp; welcome</title>', $page->html);
        assertStringContainsString('<meta name="description" content="Landing page fixture">', $page->html);
        assertStringContainsString('<link rel="stylesheet" href="/assets/cvb-base.' . $schema->baseCssHash() . '.css">', $page->html);
        assertStringContainsString('<link rel="stylesheet" href="/assets/page/7.' . $page->cssHash . '.css">', $page->html);
        assertStringContainsString("<body style=\"margin:0\">\n<div class=\"cvb-root\"><section id=\"hero\"", $page->html);
        assertStringNotContainsString('<script', $page->html);

        $edge = $renderer->render(8, 'Edge', Contract::json('fixtures/documents/edge.json'));
        assertStringNotContainsString('name="description"', $edge->html);
    }

    private static function renderer(): PageRenderer
    {
        return new PageRenderer(Contract::schema(), new RichTextSanitizer(Contract::schema()));
    }
}
