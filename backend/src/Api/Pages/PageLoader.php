<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Page\Page;
use App\Domain\Page\PageRepository;
use App\Domain\Shared\Clock;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * Resolves the `{id}` page of a route and checks the capability for it, shared by every
 * `/api/pages/{id}…` action.
 *
 * Existence is checked before permission (404 before 403), so the editor can tell a deleted page
 * from a forbidden one; ids are not secret.
 */
final readonly class PageLoader
{
    public function __construct(
        private PageRepository $pages,
        private CurrentRoute $currentRoute,
        private ApiResponder $responder,
    ) {}

    public function load(ServerRequestInterface $request, Capability $capability): Page|ResponseInterface
    {
        $id = $this->currentRoute->getArgument('id', '');
        $page = $id !== null && ctype_digit($id) ? $this->pages->findById((int) $id) : null;

        if ($page === null) {
            return $this->responder->error(Status::NOT_FOUND, 'Page not found.');
        }

        if (!Gate::can(RequireApiUser::user($request), $capability, $page)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to do that with this page.');
        }

        return $page;
    }

    /**
     * The 409 body of README §8.
     */
    public function conflict(Page $page): ResponseInterface
    {
        return $this->responder->error(Status::CONFLICT, 'The page was changed by someone else. Reload it to continue.', [
            'currentVersion' => $page->draftVersion,
            'updatedAt' => Clock::toApi($page->draftUpdatedAt),
        ]);
    }
}
