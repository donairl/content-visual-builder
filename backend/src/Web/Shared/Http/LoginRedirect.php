<?php

declare(strict_types=1);

namespace App\Web\Shared\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * Sends a guest to the login form and back to where they were going afterwards.
 */
final class LoginRedirect
{
    public static function to(ResponseFactoryInterface $responseFactory, ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $target = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

        return $responseFactory->createResponse(Status::FOUND)
            ->withHeader('Location', '/login?return=' . rawurlencode(self::safeReturn($target)))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Only local paths are followed after login: a single leading `/` (so not `//host` or `/\host`,
     * which browsers treat as another origin) and no control characters or backslashes.
     */
    public static function safeReturn(mixed $value, string $fallback = '/editor/'): string
    {
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return $fallback;
        }
        if (strlen($value) > 1 && ($value[1] === '/' || $value[1] === '\\')) {
            return $fallback;
        }
        if (preg_match('~[\x00-\x1f\x7f\\\\]~', $value) === 1 || strlen($value) > 2048) {
            return $fallback;
        }

        return $value;
    }
}
