<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Component\ComponentRepository;
use App\Domain\Component\ComponentRules;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\DocumentSanitizer;
use App\Domain\Document\Schema;
use App\Domain\Shared\Clock;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function array_key_exists;
use function is_array;

/**
 * `PUT /api/components/{id}/draft` `{ document, baseVersion }` → `{ version, updatedAt }`, with the
 * same order of checks as page drafts (413, 409, 422) plus the component rules.
 */
final readonly class SaveDraftAction
{
    public function __construct(
        private ComponentLoader $loader,
        private ComponentRepository $components,
        private ComponentRules $rules,
        private DocumentSanitizer $sanitizer,
        private Schema $schema,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $component = $this->loader->load($request, Capability::EditPages);
        if ($component instanceof ResponseInterface) {
            return $component;
        }

        $data = RequestBody::fields($request);
        $baseVersion = RequestBody::positiveInt($request, 'baseVersion');
        if ($baseVersion === null || !array_key_exists('document', $data)) {
            return $this->responder->validation([['path' => $baseVersion === null ? 'baseVersion' : 'document', 'message' => 'baseVersion and document are required.']]);
        }

        $document = $data['document'];
        $bytes = DocumentJson::byteSize($document);
        if ($bytes > $this->schema->maxDocumentBytes) {
            return $this->responder->error(Status::PAYLOAD_TOO_LARGE, 'The document exceeds ' . $this->schema->maxDocumentBytes . ' bytes.');
        }
        if ($baseVersion !== $component->draftVersion) {
            return $this->loader->conflict($component);
        }
        $errors = $this->rules->validate($document, $bytes);
        if ($errors !== [] || !is_array($document)) {
            return $this->responder->validation($errors, 'The component is invalid.');
        }
        $document = $this->sanitizer->sanitize($document);
        $errors = $this->rules->validate($document);
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The component is invalid after sanitizing its rich text.');
        }

        $saved = $this->components->saveDraft($component->id, $baseVersion, DocumentJson::encode($document));
        if ($saved === null) {
            return $this->loader->conflict($this->components->findById($component->id) ?? $component);
        }

        return $this->responder->json(Status::OK, ['version' => $saved->version, 'updatedAt' => Clock::toApi($saved->updatedAt)]);
    }
}
