<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Component\Component;
use App\Domain\Component\ComponentRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/components` → `{ items: ComponentSummary[] }`. Every component is listed for everyone
 * who edits pages: any page may use any component.
 */
final readonly class IndexAction
{
    public function __construct(
        private ComponentRepository $components,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!Gate::can(RequireApiUser::user($request), Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to edit pages.');
        }

        return $this->responder->json(Status::OK, [
            'items' => array_map(static fn(Component $c): array => $c->toSummary(), $this->components->all()),
        ]);
    }
}
