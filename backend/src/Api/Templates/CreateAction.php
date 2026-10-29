<?php

declare(strict_types=1);

namespace App\Api\Templates;

use App\Api\Components\NameRule;
use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Api\Shared\RequireApiUser;
use App\Domain\Document\DocumentJson;
use App\Domain\Document\DocumentSanitizer;
use App\Domain\Document\DocumentValidator;
use App\Domain\Document\Schema;
use App\Domain\Document\ValueValidator;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * `POST /api/templates` `{ name, kind, document }` → 201 `TemplateSummary` (README §11). The
 * document must be valid; a `section` template has exactly one top-level element.
 */
final readonly class CreateAction
{
    public function __construct(
        private TemplateRepository $templates,
        private DocumentValidator $validator,
        private DocumentSanitizer $sanitizer,
        private Schema $schema,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);
        if (!Gate::can($user, Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to save templates.');
        }

        $data = RequestBody::fields($request);
        $name = $data['name'] ?? null;
        if (!is_string($name) || ($error = NameRule::error($name)) !== null) {
            return $this->responder->validation([['path' => 'name', 'message' => $error ?? 'Enter a name.']]);
        }
        $kind = $data['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, Template::KINDS, true)) {
            return $this->responder->validation([['path' => 'kind', 'message' => 'kind must be "page" or "section".']]);
        }

        $document = $data['document'] ?? null;
        $bytes = DocumentJson::byteSize($document);
        if ($bytes > $this->schema->maxDocumentBytes) {
            return $this->responder->error(Status::PAYLOAD_TOO_LARGE, 'The document exceeds ' . $this->schema->maxDocumentBytes . ' bytes.');
        }
        $errors = $this->validator->validate($document, $bytes);
        if ($errors === [] && $kind === Template::SECTION && count(ValueValidator::asList(ValueValidator::asObject($document)['rootIds'] ?? null)) !== 1) {
            $errors[] = ['path' => 'rootIds', 'message' => 'A section template has exactly one top-level element.'];
        }
        if ($errors !== [] || !is_array($document)) {
            return $this->responder->validation($errors, 'The template is invalid.');
        }
        $document = $this->sanitizer->sanitize($document);
        $errors = $this->validator->validate($document);
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The template is invalid after sanitizing its rich text.');
        }

        $template = $this->templates->create(trim($name), $kind, DocumentJson::encode($document), $user->id);

        return $this->responder->json(Status::CREATED, $template->toSummary());
    }
}
