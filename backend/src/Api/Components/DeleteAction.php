<?php

declare(strict_types=1);

namespace App\Api\Components;

use App\Api\Shared\ApiResponder;
use App\Domain\Component\ComponentRepository;
use App\Domain\Page\PublishedPagesRenderer;
use App\Domain\User\Capability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/components/{id}` → 204. Instances of a deleted component render empty; published
 * pages are re-rendered so the live site does too.
 */
final readonly class DeleteAction
{
    public function __construct(
        private ComponentLoader $loader,
        private ComponentRepository $components,
        private PublishedPagesRenderer $published,
        private ApiResponder $responder,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $component = $this->loader->load($request, Capability::DeletePages);
        if ($component instanceof ResponseInterface) {
            return $component;
        }

        $this->components->delete($component->id);
        $this->published->rerenderAll();

        return $this->responder->noContent();
    }
}
