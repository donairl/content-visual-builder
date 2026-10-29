<?php

declare(strict_types=1);

namespace App\Web\Assets;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

final class CssResponse
{
    /**
     * A stylesheet whose URL contains its content hash, cacheable for a year.
     */
    public static function immutable(ResponseFactoryInterface $responseFactory, string $css): ResponseInterface
    {
        $response = $responseFactory->createResponse(Status::OK)
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $response->getBody()->write($css);

        return $response;
    }
}
