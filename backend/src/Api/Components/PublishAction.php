<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Component\ComponentRepository;
use App\Domain\Component\ComponentRules;
use App\Domain\Document\DocumentJson;
use App\Domain\Page\PublishedPagesRenderer;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `POST /api/components/{id}/publish` `{ baseVersion }` → `ComponentSummary & { rerendered }`.
 *
 * Publishes the stored draft (re-validated) and re-renders every published page, so every
 * instance on the live site shows the new version.
 */
final readonly class PublishAction
{
    public function __construct(
        private ComponentLoader $loader,
        private ComponentRepository $components,
        private ComponentRules $rules,
        private PublishedPagesRenderer $published,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $component = $this->loader->load($request, Capability::PublishPages);
        if ($component instanceof ResponseInterface) {
            return $component;
        }

        $baseVersion = RequestBody::positiveInt($request, 'baseVersion');
        if ($baseVersion === null) {
            return $this->responder->validation([['path' => 'baseVersion', 'message' => 'baseVersion must be a positive integer.']]);
        }
        if ($baseVersion !== $component->draftVersion) {
            return $this->loader->conflict($component);
        }

        $json = $component->draftDocument ?? '';
        $errors = $this->rules->validate(DocumentJson::decode($json), strlen($json));
        if ($errors !== []) {
            return $this->responder->validation($errors, 'The saved draft is invalid and cannot be published.');
        }
        if (!$this->components->publish($component->id, $baseVersion, $json)) {
            return $this->loader->conflict($this->components->findById($component->id) ?? $component);
        }

        $rerendered = $this->published->rerenderAll();

        return $this->responder->json(Status::OK, ($this->components->findById($component->id) ?? $component)->toSummary() + ['rerendered' => $rerendered]);
    }
}
