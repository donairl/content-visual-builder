<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function is_string;

/**
 * UTC timestamps as stored in and read back from the database.
 *
 * Columns are timezone-less `datetime`s holding UTC; every write and read goes through here so a
 * server with a non-UTC default timezone cannot shift them.
 */
final class Clock
{
    public const STORAGE_FORMAT = 'Y-m-d H:i:s';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function toStorage(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::STORAGE_FORMAT);
    }

    /**
     * A bad value degrades to `null` rather than failing the request.
     */
    public static function fromStorage(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    public static function toApi(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
