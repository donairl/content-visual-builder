<?php

declare(strict_types=1);

use App\Environment;
use App\Shared\ApplicationParams;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Assets\AssetManager;
use Yiisoft\Definitions\Reference;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    'yiisoft/view' => [
        'basePath' => null,
        'parameters' => [
            'assetManager' => Reference::to(AssetManager::class),
            'applicationParams' => Reference::to(ApplicationParams::class),
            'aliases' => Reference::to(Aliases::class),
            'urlGenerator' => Reference::to(UrlGeneratorInterface::class),
            'currentRoute' => Reference::to(CurrentRoute::class),
        ],
    ],

    /**
     * The session cookie carries the identity, so it is locked down here rather than left on PHP's
     * defaults: never readable from JavaScript, not sent on cross-site subrequests (CSRF defence in
     * depth), and `Secure` in production (see Environment::sessionCookieSecure()).
     */
    'yiisoft/session' => [
        'session' => [
            'options' => [
                'name' => 'CVBSESSID',
                'cookie_secure' => Environment::sessionCookieSecure() ? 1 : 0,
                'cookie_httponly' => 1,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => 1,
            ],
            'handler' => null,
        ],
    ],

    /**
     * Session-backed synchronizer token (the yiisoft/csrf default), exposed to the editor as the
     * "nonce" in `/api/session` and sent back in `X-CSRF-Token`. No HMAC key is needed.
     */
    'yiisoft/csrf' => [
        'hmacToken' => [
            'secretKey' => '',
            'algorithm' => 'sha256',
            'lifetime' => null,
        ],
    ],

    'yiisoft/yii-view-renderer' => [
        'viewPath' => null,
        'layout' => '@src/Web/Shared/Layout/Main/layout.php',
        'injections' => [
            Reference::to(CsrfViewInjection::class),
        ],
    ],
];
