<?php

declare(strict_types=1);

namespace App\Web\Preview;

use App\Domain\Document\DocumentJson;
use App\Domain\Page\PageRepository;
use App\Domain\Render\PublicPageRenderer;
use App\Domain\Site\SiteContextFactory;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use App\Domain\User\User;
use App\Web\Shared\Http\LoginRedirect;
use App\Web\Shared\Http\SecurityHeaders;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\User\CurrentUser;

/**
 * `GET /preview/{id}`: the current draft rendered exactly like the public page would be.
 *
 * Rendered on every request (the draft changes constantly) with the CSS inlined, never cached and
 * kept out of search engines. Requires the same rights as editing the page.
 */
final readonly class Action
{
    public function __construct(
        private PageRepository $pages,
        private PublicPageRenderer $renderer,
        private SiteContextFactory $site,
        private CurrentUser $currentUser,
        private CurrentRoute $currentRoute,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser->getIdentity();
        if (!$user instanceof User) {
            return LoginRedirect::to($this->responseFactory, $request);
        }

        $id = $this->currentRoute->getArgument('id', '') ?? '';
        $page = ctype_digit($id) ? $this->pages->findById((int) $id) : null;
        if ($page === null) {
            return $this->plain(Status::NOT_FOUND, 'Page not found.');
        }
        if (!Gate::can($user, Capability::EditPages, $page)) {
            return $this->plain(Status::FORBIDDEN, 'You are not allowed to preview this page.');
        }

        $document = DocumentJson::decode($page->draftDocument ?? '');
        $html = $this->renderer->renderPreview($page->title, $document, $this->site->forDocument($document));

        $response = $this->responseFactory->createResponse(Status::OK)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $response->getBody()->write($html);

        return SecurityHeaders::preview($response);
    }

    private function plain(int $status, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($message);

        return $response;
    }
}
