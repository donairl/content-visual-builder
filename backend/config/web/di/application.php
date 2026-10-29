<?php

declare(strict_types=1);

use App\Web\NotFound\NotFoundHandler;
use App\Web\Shared\Middleware\HostOnlySessionCookie;
use App\Web\Shared\Middleware\JsonBodyParser;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Input\Http\HydratorAttributeParametersResolver;
use Yiisoft\Input\Http\RequestInputParametersResolver;
use Yiisoft\Middleware\Dispatcher\CompositeParametersResolver;
use Yiisoft\Middleware\Dispatcher\MiddlewareDispatcher;
use Yiisoft\Middleware\Dispatcher\ParametersResolverInterface;
use Yiisoft\RequestProvider\RequestCatcherMiddleware;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Session\SessionMiddleware;
use Yiisoft\Yii\Http\Application;

/** @var array $params */

return [
    Application::class => [
        '__construct()' => [
            'dispatcher' => DynamicReference::to([
                'class' => MiddlewareDispatcher::class,
                'withMiddlewares()' => [
                    [
                        ErrorCatcher::class,
                        // Above the session: a malformed or oversized JSON body is rejected (400/413)
                        // without opening a session. Above the CSRF check and the router so both
                        // see a populated `parsedBody`.
                        JsonBodyParser::class,
                        // Strips the `Domain` attribute SessionMiddleware adds, so the session
                        // cookie is never shared with subdomains.
                        HostOnlySessionCookie::class,
                        SessionMiddleware::class,
                        // Every non-GET request needs the token ("nonce"), from the `_csrf` form
                        // field or the `X-CSRF-Token` header the editor sends.
                        CsrfTokenMiddleware::class,
                        RequestCatcherMiddleware::class,
                        Router::class,
                    ],
                    // `RequireApiUser` is not in this stack by design: it guards the API routes
                    // only and is attached in `config/common/routes.php`.
                ],
            ]),
            'fallbackHandler' => Reference::to(NotFoundHandler::class),
        ],
    ],

    ParametersResolverInterface::class => [
        'class' => CompositeParametersResolver::class,
        '__construct()' => [
            Reference::to(HydratorAttributeParametersResolver::class),
            Reference::to(RequestInputParametersResolver::class),
        ],
    ],
];
