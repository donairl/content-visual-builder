<?php

declare(strict_types=1);

namespace App\Domain\Render;

use App\Domain\Document\Schema;
use App\Domain\Render\Sanitizer\ContractParser;
use App\Domain\Render\Sanitizer\LinkAttributeSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * Sanitizes `richtext` props (the text widget) per README §6, on top of symfony/html-sanitizer.
 *
 * - Allowed: `p br strong b em i u s a ul ol li blockquote code`, attributes `a[href|target|rel]`
 *   only, filtered by {@see LinkAttributeSanitizer}.
 * - Dropped with content: see {@see ContractParser::DROPPED_ELEMENTS}.
 * - Everything else is unwrapped (default action "block": the element goes, its children stay).
 * - Comments never survive: symfony's visitor only copies elements and text.
 *
 * Runs at render time (publish, preview) and on draft save; output is idempotent.
 */
final readonly class RichTextSanitizer
{
    public const ALLOWED_ELEMENTS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'code'];

    private HtmlSanitizer $sanitizer;

    public function __construct(Schema $schema)
    {
        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block)
            // The prop length is bounded by the validator; truncating here would silently cut
            // content in the middle of a tag.
            ->withMaxInputLength(-1);

        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            if ($sanitizer instanceof UrlAttributeSanitizer) {
                $config = $config->withoutAttributeSanitizer($sanitizer);
            }
        }

        foreach (self::ALLOWED_ELEMENTS as $element) {
            $config = $config->allowElement($element, $element === 'a' ? ['href', 'target', 'rel'] : []);
        }
        foreach (ContractParser::DROPPED_ELEMENTS as $element) {
            $config = $config->dropElement($element);
        }

        $this->sanitizer = new HtmlSanitizer(
            $config->withAttributeSanitizer(new LinkAttributeSanitizer($schema)),
            new ContractParser(),
        );
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
