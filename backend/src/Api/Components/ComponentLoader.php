<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Component\Component;
use App\Domain\Component\ComponentRepository;
use App\Domain\Shared\Clock;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * Resolves the `{id}` component of a route and checks the capability for it, with the same
 * ownership rules as pages (README §10): 404 before 403.
 */
final readonly class ComponentLoader
{
    public function __construct(
        private ComponentRepository $components,
        private CurrentRoute $currentRoute,
        private ApiResponder $responder,
    ) {}

    public function load(ServerRequestInterface $request, Capability $capability): Component|ResponseInterface
    {
        $id = $this->currentRoute->getArgument('id', '');
        $component = $id !== null && ctype_digit($id) ? $this->components->findById((int) $id) : null;

        if ($component === null) {
            return $this->responder->error(Status::NOT_FOUND, 'Component not found.');
        }
        if (!Gate::canOwned(RequireApiUser::user($request), $capability, $component->authorId)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to do that with this component.');
        }

        return $component;
    }

    public function conflict(Component $component): ResponseInterface
    {
        return $this->responder->error(Status::CONFLICT, 'The component was changed by someone else. Reload it to continue.', [
            'currentVersion' => $component->draftVersion,
            'updatedAt' => Clock::toApi($component->draftUpdatedAt),
        ]);
    }
}
