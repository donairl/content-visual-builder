<?php

declare(strict_types=1);

namespace App\Web\Assets;

use App\Domain\Document\Schema;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * `GET /assets/cvb-base.{hash}.css`: `schema/base.css`, served from the contract directory itself.
 *
 * The hash makes the URL immutable. A stale hash (a page published before base.css changed and not
 * yet re-rendered) is redirected to the current file rather than left unstyled.
 */
final readonly class BaseCssAction
{
    public function __construct(
        private Schema $schema,
        private CurrentRoute $currentRoute,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $hash = $this->schema->baseCssHash();

        if ($this->currentRoute->getArgument('hash') !== $hash) {
            return $this->responseFactory->createResponse(Status::FOUND)
                ->withHeader('Location', '/assets/cvb-base.' . $hash . '.css')
                ->withHeader('Cache-Control', 'no-cache');
        }

        return CssResponse::immutable($this->responseFactory, $this->schema->baseCss);
    }
}
