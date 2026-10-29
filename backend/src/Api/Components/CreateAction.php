<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Api\Shared\RequireApiUser;
use App\Domain\Component\ComponentRepository;
use App\Domain\Component\ComponentRules;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\DocumentSanitizer;
use App\Domain\Document\Schema;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * `POST /api/components` `{ name, document }` → 201 `ComponentDetail`. The component is published at
 * once (version 1) so the element it was made from can be replaced by an instance right away.
 */
final readonly class CreateAction
{
    public function __construct(
        private ComponentRepository $components,
        private ComponentRules $rules,
        private DocumentSanitizer $sanitizer,
        private Schema $schema,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to create components.');
        }

        $data = RequestBody::fields($request);
        $name = $data['name'] ?? null;
        if (!is_string($name) || NameRule::error($name) !== null) {
            return $this->responder->validation([['path' => 'name', 'message' => NameRule::error(is_string($name) ? $name : '') ?? 'Enter a name.']]);
        }
        if (!array_key_exists('document', $data)) {
            return $this->responder->validation([['path' => 'document', 'message' => 'The document is required.']]);
        }

        $document = $data['document'];
        $bytes = DocumentJson::byteSize($document);
        if ($bytes > $this->schema->maxDocumentBytes) {
            return $this->responder->error(Status::PAYLOAD_TOO_LARGE, 'The document exceeds ' . $this->schema->maxDocumentBytes . ' bytes.');
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

        $component = $this->components->create(trim($name), $user->id, DocumentJson::encode($document));

        return $this->responder->json(Status::CREATED, ComponentDetail::of($component));
    }
}
