<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Domain\Page\PageRepository;
use App\Domain\Revision\RevisionRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/pages/{id}` → 204.
 */
final readonly class DeleteAction
{
    public function __construct(
        private PageLoader $loader,
        private PageRepository $pages,
        private RevisionRepository $revisions,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::DeletePages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $this->pages->delete($page->id);
        $this->revisions->deleteForPage($page->id);

        return $this->responder->noContent();
    }
}
