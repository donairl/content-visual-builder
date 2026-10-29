<?php

declare(strict_types=1);

namespace App\Api\Shared;

use App\Domain\User\User;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Http\Status;
use Yiisoft\User\CurrentUser;

/**
 * Gate for the API routes: a guest gets 401 JSON instead of the login redirect web routes use —
 * the editor handles that status itself.
 *
 * The check is on the identity, not on a session flag, so a user deleted from the database is
 * locked out on the very next request. The identity is handed to the action as a request attribute.
 */
final readonly class RequireApiUser implements MiddlewareInterface
{
    private const ATTRIBUTE = 'cvb.user';

    public function __construct(
        private CurrentUser $currentUser,
        private ApiResponder $responder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $this->currentUser->getIdentity();

        if (!$identity instanceof User) {
            return $this->responder->error(Status::UNAUTHORIZED, 'Please sign in.');
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $identity));
    }

    /**
     * The signed-in user of a request that passed this middleware.
     */
    public static function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(self::ATTRIBUTE);
        if (!$user instanceof User) {
            throw new LogicException('RequireApiUser must guard this route.');
        }

        return $user;
    }
}
