<?php

declare(strict_types=1);

namespace App\Web\PublicPage;

use App\Domain\Page\PageRepository;
use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Http\SecurityHeaders;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * `GET /{slug}`: serves the HTML stored at publish time, byte for byte.
 *
 * Nothing is rendered per request — the page was rendered from the published snapshot when it was
 * published, so later draft edits cannot leak onto the live page. Drafts and unknown slugs are 404.
 */
final readonly class Action
{
    public function __construct(
        private PageRepository $pages,
        private CurrentRoute $currentRoute,
        private ResponseFactoryInterface $responseFactory,
        private NotFoundHandler $notFound,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $slug = $this->currentRoute->getArgument('slug', '') ?? '';
        $page = $slug === '' ? null : $this->pages->findPublishedBySlug($slug);

        if ($page === null || $page->publishedHtml === null) {
            return $this->notFound->handle($request);
        }

        $response = $this->responseFactory->createResponse(Status::OK)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Revalidate every time so an unpublish takes effect immediately.
            ->withHeader('Cache-Control', 'no-cache');
        $response->getBody()->write($page->publishedHtml);

        return SecurityHeaders::publicPage($response);
    }
}
