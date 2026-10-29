<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Component\ComponentRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

/**
 * `GET /api/components/{id}` → `ComponentDetail`. Readable by everyone who edits pages, since any
 * page may show the component; changing it follows ownership.
 */
final readonly class ShowAction
{
    public function __construct(
        private ComponentRepository $components,
        private CurrentRoute $currentRoute,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!Gate::can(RequireApiUser::user($request), Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to edit pages.');
        }
        $id = $this->currentRoute->getArgument('id', '') ?? '';
        $component = ctype_digit($id) ? $this->components->findById((int) $id) : null;
        if ($component === null) {
            return $this->responder->error(Status::NOT_FOUND, 'Component not found.');
        }

        return $this->responder->json(Status::OK, ComponentDetail::of($component));
    }
}
