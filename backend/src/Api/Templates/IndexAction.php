<?php

declare(strict_types=1);

namespace App\Api\Templates;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/templates` → `{ items: TemplateSummary[] }`, newest first. The built-in templates are
 * part of the editor (`schema/templates/`), not of this list.
 */
final readonly class IndexAction
{
    public function __construct(
        private TemplateRepository $templates,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!Gate::can(RequireApiUser::user($request), Capability::EditPages)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to edit pages.');
        }

        return $this->responder->json(Status::OK, [
            'items' => array_map(static fn(Template $t): array => $t->toSummary(), $this->templates->all()),
        ]);
    }
}
