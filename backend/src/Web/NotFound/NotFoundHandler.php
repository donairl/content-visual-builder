<?php

declare(strict_types=1);

namespace App\Web\NotFound;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The 404 page, for unmatched routes and for slugs that are not published.
 */
final readonly class NotFoundHandler implements RequestHandlerInterface
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->viewRenderer
            ->render(__DIR__ . '/template')
            ->withStatus(Status::NOT_FOUND)
            ->withHeader('Cache-Control', 'no-cache');

        return $response->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
