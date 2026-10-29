<?php

declare(strict_types=1);

namespace App\Api\Pages;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\DocumentSanitizer;
use App\Domain\Document\DocumentValidator;
use App\Domain\Document\Schema;
use App\Api\Shared\RequireApiUser;
use App\Domain\Page\PageRepository;
use App\Domain\Revision\Revision;
use App\Domain\Revision\RevisionRepository;
use App\Domain\Shared\Clock;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function array_key_exists;
use function is_array;

/**
 * `PUT /api/pages/{id}/draft` `{ document, baseVersion }` → `{ version, updatedAt }`.
 *
 * Order of checks: size (413) before anything that walks the document, then version (409) so a
 * stale editor learns about the conflict before fixing validation errors, then validation (422).
 * The final write is a compare-and-swap, so a save that races another one still gets its 409.
 */
final readonly class SaveDraftAction
{
    public function __construct(
        private PageLoader $loader,
        private PageRepository $pages,
        private RevisionRepository $revisions,
        private DocumentValidator $validator,
        private DocumentSanitizer $sanitizer,
        private Schema $schema,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->loader->load($request, Capability::EditPages);
        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $data = RequestBody::fields($request);
        $baseVersion = RequestBody::positiveInt($request, 'baseVersion');
        $errors = [];
        if ($baseVersion === null) {
            $errors[] = ['path' => 'baseVersion', 'message' => 'baseVersion must be a positive integer.'];
        }
        if (!array_key_exists('document', $data)) {
            $errors[] = ['path' => 'document', 'message' => 'The document is required.'];
        }
        if ($errors !== [] || $baseVersion === null) {
            return $this->responder->validation($errors);
        }

        $document = $data['document'] ?? null;
        $bytes = DocumentJson::byteSize($document);
        if ($bytes > $this->schema->maxDocumentBytes) {
            return $this->responder->error(
                Status::PAYLOAD_TOO_LARGE,
                'The document exceeds ' . $this->schema->maxDocumentBytes . ' bytes.',
            );
        }

        if ($baseVersion !== $page->draftVersion) {
            return $this->loader->conflict($page);
        }

        $errors = $this->validator->validate($document, $bytes);
        if ($errors !== [] || !is_array($document)) {
            return $this->responder->validation($errors, 'The document is invalid.');
        }

        // Sanitizing can lengthen rich text (entity encoding), so the result is checked again.
        $document = $this->sanitizer->sanitize($document);
        $errors = $this->validator->validate($document);
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The document is invalid after sanitizing its rich text.');
        }

        $json = DocumentJson::encode($document);
        $saved = $this->pages->saveDraft($page->id, $baseVersion, $json);
        if ($saved === null) {
            return $this->loader->conflict($this->pages->findById($page->id) ?? $page);
        }
        $this->revisions->record($page->id, Revision::DRAFT, $saved->version, $json, RequireApiUser::user($request)->id);

        return $this->responder->json(Status::OK, [
            'version' => $saved->version,
            'updatedAt' => Clock::toApi($saved->updatedAt),
        ]);
    }
}
