<?php

declare(strict_types=1);

namespace App\Web\Auth\Login;

use App\Web\Shared\Http\LoginRedirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * `GET /login?return=/path`. A signed-in visitor goes straight to the return path.
 */
final readonly class ShowAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private CurrentUser $currentUser,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $return = LoginRedirect::safeReturn($request->getQueryParams()['return'] ?? null);

        if (!$this->currentUser->isGuest()) {
            return $this->responseFactory->createResponse(Status::FOUND)->withHeader('Location', $return);
        }

        return $this->viewRenderer
            ->render(__DIR__ . '/template', ['email' => '', 'return' => $return, 'error' => null])
            ->withHeader('Cache-Control', 'no-store');
    }
}
