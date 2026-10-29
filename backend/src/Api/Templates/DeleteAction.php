<?php

declare(strict_types=1);

namespace App\Api\Templates;

use App\Api\Shared\ApiResponder;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/templates/{id}` → 204. Pages made from the template are copies and stay as they are.
 */
final readonly class DeleteAction
{
    public function __construct(
        private TemplateLoader $loader,
        private TemplateRepository $templates,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->loader->load($request, Capability::DeletePages, owned: true);
        if ($template instanceof ResponseInterface) {
            return $template;
        }
        $this->templates->delete($template->id);

        return $this->responder->noContent();
    }
}
