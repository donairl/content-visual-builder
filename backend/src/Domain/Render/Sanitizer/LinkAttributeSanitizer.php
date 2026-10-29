<?php

declare(strict_types=1);

namespace App\Domain\Render\Sanitizer;

use App\Domain\Document\Schema;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Applies the contract's link rules to `<a>` attributes (README §6).
 *
 * Replaces symfony's UrlAttributeSanitizer for links: that one normalises URLs (lowercases schemes,
 * re-serialises), whereas the contract keeps an `href` only if it matches `patterns.linkUrl`
 * verbatim — so `HTTPS://EXAMPLE.COM` is dropped, exactly as the editor's sanitizer drops it.
 */
final readonly class LinkAttributeSanitizer implements AttributeSanitizerInterface
{
    public function __construct(
        private Schema $schema,
    ) {}

    public function getSupportedElements(): ?array
    {
        return ['a'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['href', 'target', 'rel'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match ($attribute) {
            'href' => $this->schema->matches('linkUrl', $value) ? $value : null,
            'target' => $value === '_blank' ? $value : null,
            // ContractParser has already derived rel from target; anything else is foreign.
            'rel' => $value === ContractParser::SAFE_REL ? $value : null,
            default => null,
        };
    }
}
