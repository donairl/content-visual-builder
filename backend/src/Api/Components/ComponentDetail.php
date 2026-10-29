<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Domain\Component\Component;
use App\Domain\Document\DocumentJson;

/**
 * `ComponentDetail` of README §10: the summary plus the draft (`document`, for editing) and the
 * published document (`publishedDocument`, what instances render).
 */
final class ComponentDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Component $component): array
    {
        return $component->toSummary() + [
            'document' => DocumentJson::decodeForResponse($component->draftDocument ?? '{}'),
            'publishedDocument' => DocumentJson::decodeForResponse($component->publishedDocument ?? '{}'),
        ];
    }
}
