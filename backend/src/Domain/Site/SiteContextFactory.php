<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Component\ComponentRepository;
use App\Domain\Document\ValueValidator;
use App\Domain\Render\SiteContext;

use function array_keys;
use function is_float;
use function is_int;

/**
 * Builds the site data pages render against: the current global styles (README §9) and the
 * published documents of the components a page uses (README §10).
 */
final readonly class SiteContextFactory
{
    public function __construct(
        private GlobalStyles $globals,
        private ComponentRepository $components,
    ) {}

    /**
     * For rendering one document: only the components it references are loaded.
     */
    public function forDocument(mixed $document): SiteContext
    {
        $ids = [];
        foreach (ValueValidator::asObject(ValueValidator::asObject($document)['nodes'] ?? null) as $node) {
            $node = ValueValidator::asObject($node);
            $id = ValueValidator::asObject($node['props'] ?? null)['componentId'] ?? null;
            if (($node['type'] ?? null) === 'component' && (is_int($id) || is_float($id)) && $id >= 1) {
                $ids[(int) $id] = true;
            }
        }

        return $this->globals->siteContext()->withComponents($this->components->publishedDocuments(array_keys($ids)));
    }

    /**
     * For rendering many pages: every component.
     */
    public function forAll(): SiteContext
    {
        return $this->globals->siteContext()->withComponents($this->components->publishedDocuments());
    }
}
