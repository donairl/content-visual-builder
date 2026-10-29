<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/pages/{id}` → PageDetail with the draft document.
 */
final readonly class ShowAction
{
    public function __construct(
        private PageLoader $loader,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::EditPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        return $this->responder->json(Status::OK, PageDetail::of($page));
    }
}
