<?php

declare(strict_types=1);

namespace App\Api\Shared;

use Psr\Http\Message\ServerRequestInterface;

use function is_array;

/**
 * Typed access to the decoded JSON body (decoded by {@see \App\Web\Shared\Middleware\JsonBodyParser}).
 */
final class RequestBody
{
    /**
     * @return array<array-key, mixed>
     */
    public static function fields(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * A positive integer field (JSON number, integer-valued), or `null` when absent or malformed.
     */
    public static function positiveInt(ServerRequestInterface $request, string $name): ?int
    {
        $value = self::fields($request)[$name] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value && $value < PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }
}
