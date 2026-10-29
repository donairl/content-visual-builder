<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Page\Page;
use App\Domain\Page\PageRepository;
use App\Domain\Page\PageStatus;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/pages?q=&status=`.
 *
 * Users without `edit_others_pages` (authors) only see their own pages: listing pages they can
 * neither open nor edit would only produce 403s in the editor.
 */
final readonly class IndexAction
{
    public function __construct(
        private PageRepository $pages,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to edit pages.');
        }

        $query = $request->getQueryParams();
        $search = $query['q'] ?? null;
        $search = is_string($search) ? $search : null;
        $statusParam = $query['status'] ?? '';
        $statusParam = is_string($statusParam) ? $statusParam : '';
        $status = null;
        if ($statusParam !== '') {
            $status = PageStatus::tryFrom($statusParam);
            if ($status === null) {
                return $this->responder->validation([['path' => 'status', 'message' => 'Status must be "draft" or "published".']]);
            }
        }

        $authorId = Gate::can($user, Capability::EditOthersPages) ? null : $user->id;

        return $this->responder->json(Status::OK, [
            'items' => array_map(
                static fn(Page $page): array => $page->toSummary(),
                $this->pages->search($authorId, $search, $status),
            ),
        ]);
    }
}
