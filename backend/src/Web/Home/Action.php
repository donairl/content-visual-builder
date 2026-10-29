<?php

declare(strict_types=1);

namespace App\Web\Home;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

/**
 * `/` → the editor. The site's front page is not a CMS page in Scope 1.
 */
final readonly class Action
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->responseFactory->createResponse(Status::FOUND)->withHeader('Location', '/editor/');
    }
}
