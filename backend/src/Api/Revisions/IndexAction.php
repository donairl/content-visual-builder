<?php

declare(strict_types=1);

namespace App\Api\Revisions;

use App\Api\Pages\PageLoader;
use App\Api\Shared\ApiResponder;
use App\Domain\Revision\Revision;
use App\Domain\Revision\RevisionRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/pages/{id}/revisions` → `{ items: RevisionSummary[] }`, newest first (README §8).
 */
final readonly class IndexAction
{
    public function __construct(
        private PageLoader $loader,
        private RevisionRepository $revisions,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::EditPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        return $this->responder->json(Status::OK, [
            'items' => array_map(static fn(Revision $revision): array => $revision->toSummary(), $this->revisions->list($page->id)),
        ]);
    }
}
