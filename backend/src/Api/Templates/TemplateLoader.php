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
use Yiisoft\Router\CurrentRoute;

/**
 * Resolves the `{id}` template of a route: 404 before 403, ownership as for pages (README §11).
 */
final readonly class TemplateLoader
{
    public function __construct(
        private TemplateRepository $templates,
        private CurrentRoute $currentRoute,
        private ApiResponder $responder,
    ) {}

    /**
     * @param bool $owned Whether the capability applies to the template's author (rename, delete)
     *                    rather than to the library as a whole (reading).
     */
    public function load(ServerRequestInterface $request, Capability $capability, bool $owned): Template|ResponseInterface
    {
        $id = $this->currentRoute->getArgument('id', '');
        $template = $id !== null && ctype_digit($id) ? $this->templates->findById((int) $id) : null;
        if ($template === null) {
            return $this->responder->error(Status::NOT_FOUND, 'Template not found.');
        }
        if (!Gate::canOwned(RequireApiUser::user($request), $capability, $owned ? $template->authorId : null)) {
            return $this->responder->error(Status::FORBIDDEN, 'You are not allowed to do that with this template.');
        }

        return $template;
    }
}
