<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Domain\Document\ValueValidator;

/**
 * Names of components and templates: 1–120 characters after trimming.
 */
final class NameRule
{
    public const int MAX = 120;

    public static function error(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Enter a name.';
        }

        return ValueValidator::length($name) > self::MAX ? 'At most ' . self::MAX . ' characters.' : null;
    }
}
