<?php

declare(strict_types=1);

namespace App\Api\NotFound;

use App\Api\Shared\ApiResponder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;

/**
 * Catch-all for unknown `/api/*` URLs, so API clients always get JSON rather than the HTML 404 page.
 */
final readonly class Action
{
    public function __construct(
        private ApiResponder $responder,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->responder->error(Status::NOT_FOUND, 'Unknown API endpoint.');
    }
}
