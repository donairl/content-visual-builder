<?php

declare(strict_types=1);

namespace App\Api\Templates;

use App\Api\Shared\ApiResponder;
use App\Domain\Document\DocumentJson;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/templates/{id}` → `TemplateSummary & { document }`.
 */
final readonly class ShowAction
{
    public function __construct(
        private TemplateLoader $loader,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $template = $this->loader->load($request, Capability::EditPages, owned: false);
        if ($template instanceof ResponseInterface) {
            return $template;
        }

        return $this->responder->json(Status::OK, $template->toSummary() + [
            'document' => DocumentJson::decodeForResponse($template->document ?? '{}'),
        ]);
    }
}
