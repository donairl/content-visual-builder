<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Component\ComponentRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function is_string;

/**
 * `PATCH /api/components/{id}` `{ name }` → `ComponentSummary`.
 */
final readonly class UpdateAction
{
    public function __construct(
        private ComponentLoader $loader,
        private ComponentRepository $components,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $component = $this->loader->load($request, Capability::EditPages);
        if ($component instanceof ResponseInterface) {
            return $component;
        }

        $name = RequestBody::fields($request)['name'] ?? null;
        if (!is_string($name) || ($error = NameRule::error($name)) !== null) {
            return $this->responder->validation([['path' => 'name', 'message' => $error ?? 'Enter a name.']]);
        }
        $this->components->rename($component->id, trim($name));

        return $this->responder->json(Status::OK, ($this->components->findById($component->id) ?? $component)->toSummary());
    }
}
