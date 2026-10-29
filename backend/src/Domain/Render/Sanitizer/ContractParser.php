<?php

declare(strict_types=1);

namespace App\Domain\Render\Sanitizer;

use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;

use function in_array;

/**
 * Parses rich text like symfony's NativeParser, then applies the two rules of README §6 that an
 * attribute-level sanitizer cannot express:
 *
 * - Elements that must disappear *with their content* are removed here. `HtmlSanitizerConfig::
 *   dropElement()` cannot do it for `style`: symfony treats it as a `<head>` element and ignores the
 *   configuration in body context, so with the "unwrap unknown elements" default its CSS text would
 *   survive as visible text.
 * - `rel` depends on `target`, a cross-attribute rule: `target="_blank"` forces
 *   `rel="noopener noreferrer"`, anything else loses `rel` entirely.
 */
final class ContractParser implements ParserInterface
{
    /**
     * README §6: removed together with their content.
     */
    public const DROPPED_ELEMENTS = [
        'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript',
        'svg', 'math', 'form', 'input', 'textarea', 'select', 'button',
    ];

    public const SAFE_REL = 'noopener noreferrer';

    public function parse(string $html, string $context = 'body'): ?\Dom\Node
    {
        /** @var HTMLDocument $document Psalm's stubs predate the PHP 8.4 DOM API. */
        $document = HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html . '</body>', LIBXML_NOERROR);
        /** @var Element|null $body */
        $body = $document->body;
        if (!$body instanceof Element) {
            return null;
        }

        $remove = [];
        /** @var Element $element */
        foreach ($body->getElementsByTagName('*') as $element) {
            /** @var string $localName */
            $localName = $element->localName;
            $name = strtolower($localName);
            if (in_array($name, self::DROPPED_ELEMENTS, true)) {
                $remove[] = $element;
            } elseif ($name === 'a') {
                self::normalizeRel($element);
            }
        }
        foreach ($remove as $element) {
            // A dropped element may sit inside another one that is already detached; remove() on a
            // detached subtree is a harmless no-op.
            $element->remove();
        }

        return $body->hasChildNodes() ? $body : null;
    }

    private static function normalizeRel(Element $link): void
    {
        if ($link->getAttribute('target') === '_blank') {
            $link->setAttribute('rel', self::SAFE_REL);
        } else {
            $link->removeAttribute('rel');
        }
    }
}
