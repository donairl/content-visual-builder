<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Document\Schema;
use App\Domain\Render\SiteContext;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use RuntimeException;

use function dirname;

/**
 * Access to the shared contract (`schema/`) and its fixtures, plus the DOM comparison the README
 * defines for HTML parity.
 */
final class Contract
{
    public static function dir(): string
    {
        return dirname(__DIR__, 3) . '/schema';
    }

    public static function schema(): Schema
    {
        return Schema::fromDirectory(self::dir());
    }

    public static function read(string $relativePath): string
    {
        $contents = file_get_contents(self::dir() . '/' . $relativePath);
        if ($contents === false) {
            throw new RuntimeException('Missing fixture ' . $relativePath);
        }

        return $contents;
    }

    public static function json(string $relativePath): mixed
    {
        return json_decode(self::read($relativePath), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The site data a parity fixture renders against: `fixtures/documents/{name}.context.json` with
     * `globals` and `components` (README §9, §10), or an empty site.
     */
    public static function site(string $fixture): SiteContext
    {
        $file = 'fixtures/documents/' . $fixture . '.context.json';
        $context = is_file(self::dir() . '/' . $file) ? self::json($file) : [];
        $context = is_array($context) ? $context : [];
        $components = [];
        foreach (is_array($context['components'] ?? null) ? $context['components'] : [] as $id => $document) {
            $components[(int) $id] = $document;
        }

        return SiteContext::fromGlobals(self::schema(), $context['globals'] ?? null)->withComponents($components);
    }

    /**
     * Canonical form of an HTML fragment for "DOM-equal" comparisons: element names, attributes
     * sorted by name, text verbatim; whitespace-only text nodes and comments are dropped. Attribute
     * order and entity spelling therefore do not matter, as README §5 specifies.
     */
    public static function canonicalDom(string $html): string
    {
        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
        );

        return $document->body === null ? '' : self::children($document->body);
    }

    private static function children(Node $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof Element) {
                $attributes = [];
                foreach ($child->attributes as $attribute) {
                    $attributes[$attribute->name] = $attribute->value;
                }
                ksort($attributes);

                $out .= '<' . ($child->namespaceURI ?? '') . ':' . $child->localName;
                foreach ($attributes as $name => $value) {
                    $out .= ' ' . $name . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $out .= '>' . self::children($child) . '</' . $child->localName . '>';
            } elseif ($child instanceof Text) {
                $text = $child->textContent ?? '';
                if (trim($text) !== '') {
                    $out .= json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $out;
    }
}
