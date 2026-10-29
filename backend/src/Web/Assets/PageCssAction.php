<?php

declare(strict_types=1);

namespace App\Web\Assets;

use App\Domain\Page\PageRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * `GET /assets/page/{id}.{hash}.css`: a published page's stylesheet, from the database.
 *
 * Only the hash stored at publish time is served (404 otherwise), which is what makes the long
 * immutable cache lifetime safe: a republish changes the URL.
 */
final readonly class PageCssAction
{
    public function __construct(
        private PageRepository $pages,
        private CurrentRoute $currentRoute,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $id = $this->currentRoute->getArgument('id', '') ?? '';
        $hash = $this->currentRoute->getArgument('hash', '') ?? '';
        $page = ctype_digit($id) ? $this->pages->findPublishedCss((int) $id) : null;

        if ($page === null || $page->publishedCss === null || $page->publishedCssHash === null
            || !hash_equals($page->publishedCssHash, $hash)
        ) {
            return $this->responseFactory->createResponse(Status::NOT_FOUND)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Cache-Control', 'no-cache');
        }

        return CssResponse::immutable($this->responseFactory, $page->publishedCss);
    }
}
