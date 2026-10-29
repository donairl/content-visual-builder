<?php

declare(strict_types=1);

use App\Web\Shared\Http\CsrfFailureHandler;
use Yiisoft\Csrf\CsrfTokenMiddleware;
use Yiisoft\Definitions\Reference;

return [
    CsrfTokenMiddleware::class => [
        '__construct()' => [
            'failureHandler' => Reference::to(CsrfFailureHandler::class),
        ],
    ],
];
