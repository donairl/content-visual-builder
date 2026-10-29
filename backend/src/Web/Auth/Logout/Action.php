<?php

declare(strict_types=1);

namespace App\Web\Auth\Logout;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\User\CurrentUser;

/**
 * `POST /logout` (CSRF-checked): ends the session and returns to the login form.
 *
 * `CurrentUser::logout()` regenerates the session id, so the old one cannot be replayed. Logging
 * out as a guest is a no-op that still redirects, so a double submit is not an error.
 */
final readonly class Action
{
    public function __construct(
        private CurrentUser $currentUser,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $this->currentUser->logout();

        return $this->responseFactory->createResponse(Status::SEE_OTHER)->withHeader('Location', '/login');
    }
}
