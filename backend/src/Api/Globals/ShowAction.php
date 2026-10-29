<?php

declare(strict_types=1);

namespace App\Api\Globals;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Document\DocumentJson;
use App\Domain\Shared\Clock;
use App\Domain\Site\GlobalStyles;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/globals` → `{ globals, version, updatedAt }` (README §9). Everyone who edits pages needs
 * the globals to render them; `version` 0 means the schema defaults were never saved.
 */
final readonly class ShowAction
{
    public function __construct(
        private GlobalStyles $globals,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!Gate::can(RequireApiUser::user($request), Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to edit pages.');
        }

        $current = $this->globals->current();

        return $this->responder->json(Status::OK, [
            'globals' => DocumentJson::toJsonValue($current->value, null, DocumentJson::GLOBALS_LIST_KEYS),
            'version' => $current->version,
            'updatedAt' => Clock::toApi($current->updatedAt),
        ]);
    }
}
