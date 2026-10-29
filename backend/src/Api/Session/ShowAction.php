<?php

declare(strict_types=1);

namespace App\Api\Session;

use App\Api\Shared\ApiResponder;
use App\Api\Shared\RequireApiUser;
use App\Domain\User\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Csrf\CsrfTokenInterface;
use Yiisoft\Http\Status;

/**
 * `GET /api/session`: who is signed in, what they may do, and the CSRF token ("nonce") the editor
 * must send as `X-CSRF-Token` on every write.
 */
final readonly class ShowAction
{
    public function __construct(
        private ApiResponder $responder,
        private CsrfTokenInterface $csrfToken,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = RequireApiUser::user($request);

        return $this->responder->json(Status::OK, [
            'user' => $user->toArray(),
            'capabilities' => Gate::capabilityNames($user),
            'csrfToken' => $this->csrfToken->getValue(),
        ]);
    }
}
