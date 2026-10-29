<?php

declare(strict_types=1);

namespace App\Api\Templates;

use App\Api\Components\NameRule;
use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequestBody;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

use function is_string;

/**
 * `PATCH /api/templates/{id}` `{ name }` → `TemplateSummary`.
 */
final readonly class UpdateAction
{
    public function __construct(
        private TemplateLoader $loader,
        private TemplateRepository $templates,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->loader->load($request, Capability::EditPages, owned: true);
        if ($template instanceof ResponseInterface) {
            return $template;
        }

        $name = RequestBody::fields($request)['name'] ?? null;
        if (!is_string($name) || ($error = NameRule::error($name)) !== null) {
            return $this->responder->validation([['path' => 'name', 'message' => $error ?? 'Enter a name.']]);
        }
        $this->templates->rename($template->id, trim($name));

        return $this->responder->json(Status::OK, ($this->templates->findById($template->id) ?? $template)->toSummary());
    }
}
