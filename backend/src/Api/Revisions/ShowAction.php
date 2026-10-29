<?php

declare(strict_types=1);

namespace App\Api\Revisions;

use App\Api\Pages\PageLoader;
use App\Api\Shared\ApiResponder;
use App\Domain\Document\DocumentJson;
use App\Domain\Revision\RevisionRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * `GET /api/pages/{id}/revisions/{revision}` → `RevisionSummary & { document }` (README §8).
 *
 * Restoring is done by the editor: it loads this document as a new edit, which autosave stores as
 * the next draft version, so a restore is itself undoable and recorded.
 */
final readonly class ShowAction
{
    public function __construct(
        private PageLoader $loader,
        private RevisionRepository $revisions,
        private CurrentRoute $currentRoute,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::EditPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $id = $this->currentRoute->getArgument('revision', '') ?? '';
        $revision = ctype_digit($id) ? $this->revisions->find($page->id, (int) $id) : null;
        if ($revision === null) {
            return $this->responder->error(Status::NOT_FOUND, 'Revision not found.');
        }

        return $this->responder->json(Status::OK, $revision->toSummary() + [
            'document' => DocumentJson::decodeForResponse($revision->document ?? '{}'),
        ]);
    }
}
