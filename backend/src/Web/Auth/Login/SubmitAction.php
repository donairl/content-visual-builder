<?php

declare(strict_types=1);

namespace App\Web\Auth\Login;

use App\Domain\User\UserRepository;
use App\Web\Shared\Http\LoginRedirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * `POST /login`: verifies credentials and opens a session (CSRF-checked by the middleware).
 *
 * Every failure — unknown address, wrong password, blank field — gives the same message, so the
 * form cannot be used to discover which addresses have accounts. The password is never echoed.
 * `CurrentUser::login()` regenerates the session id, defeating session fixation.
 */
final readonly class SubmitAction
{
    private const FAILURE_MESSAGE = 'Those credentials do not match our records.';

    public function __construct(
        private UserRepository $users,
        private CurrentUser $currentUser,
        private WebViewRenderer $viewRenderer,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $data = is_array($body) ? $body : [];
        $email = $data['email'] ?? '';
        $email = is_string($email) ? trim($email) : '';
        // Not trimmed: leading and trailing spaces are part of a password.
        $password = $data['password'] ?? '';
        $password = is_string($password) ? $password : '';
        $return = LoginRedirect::safeReturn($request->getQueryParams()['return'] ?? null);

        $user = $this->users->authenticate($email, $password);

        if ($user === null || !$this->currentUser->login($user)) {
            return $this->viewRenderer
                ->render(__DIR__ . '/template', ['email' => $email, 'return' => $return, 'error' => self::FAILURE_MESSAGE])
                ->withStatus(Status::UNPROCESSABLE_ENTITY)
                ->withHeader('Cache-Control', 'no-store');
        }

        return $this->responseFactory->createResponse(Status::SEE_OTHER)->withHeader('Location', $return);
    }
}
