<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Domain\Page\PageRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `POST /api/pages/{id}/unpublish` → PageSummary. The draft is kept; the public URL answers 404.
 */
final readonly class UnpublishAction
{
    public function __construct(
        private PageLoader $loader,
        private PageRepository $pages,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::PublishPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $this->pages->unpublish($page->id);

        return $this->responder->json(Status::OK, ($this->pages->findById($page->id) ?? $page)->toSummary());
    }
}
