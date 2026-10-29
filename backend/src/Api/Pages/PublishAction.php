<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\DocumentValidator;
use App\Domain\Page\PageRepository;
use App\Api\Shared\RequireApiUser;
use App\Domain\Render\PublicPageRenderer;
use App\Domain\Revision\Revision;
use App\Domain\Revision\RevisionRepository;
use App\Domain\Site\SiteContextFactory;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `POST /api/pages/{id}/publish` `{ baseVersion }` → PageSummary.
 *
 * Publishes the *stored* draft, never a document from the request: what goes live is exactly what
 * the editor saved at `baseVersion`. The draft is re-validated (the schema may have changed since
 * it was saved), rendered to HTML + CSS, and stored only if the draft is still at that version.
 */
final readonly class PublishAction
{
    public function __construct(
        private PageLoader $loader,
        private PageRepository $pages,
        private DocumentValidator $validator,
        private PublicPageRenderer $renderer,
        private SiteContextFactory $site,
        private RevisionRepository $revisions,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::PublishPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $baseVersion = RequestBody::positiveInt($request, 'baseVersion');
        if ($baseVersion === null) {
            return $this->responder->validation([['path' => 'baseVersion', 'message' => 'baseVersion must be a positive integer.']]);
        }
        if ($baseVersion !== $page->draftVersion) {
            return $this->loader->conflict($page);
        }

        $json = $page->draftDocument ?? '';
        $document = DocumentJson::decode($json);
        $errors = $this->validator->validate($document, strlen($json));
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The saved draft is invalid and cannot be published.');
        }

        $rendered = $this->renderer->render($page->id, $page->title, $document, $this->site->forDocument($document));

        if (!$this->pages->publish($page->id, $baseVersion, $json, $rendered)) {
            return $this->loader->conflict($this->pages->findById($page->id) ?? $page);
        }
        $this->revisions->record($page->id, Revision::PUBLISHED, $baseVersion, $json, RequireApiUser::user($request)->id);

        return $this->responder->json(Status::OK, ($this->pages->findById($page->id) ?? $page)->toSummary());
    }
}
