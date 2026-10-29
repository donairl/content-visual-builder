<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Domain\Document\DocumentJson;
use App\Domain\Page\Page;

/**
 * `PageDetail` from README §8: the summary plus the draft document.
 */
final class PageDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Page $page): array
    {
        // Decoded with objects kept as objects, so `{}` round-trips as `{}` and not `[]`.
        return $page->toSummary() + ['document' => DocumentJson::decodeForResponse($page->draftDocument ?? '{}')];
    }
}
