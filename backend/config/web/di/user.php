<?php

declare(strict_types=1);

/**
 * Gives `CurrentUser` the session it stores the identity in. yiisoft/user registers `CurrentUser`
 * but leaves the session to the application; without it `login()` writes the identity nowhere and
 * every request sees a guest.
 */

use Yiisoft\Definitions\Reference;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

return [
    CurrentUser::class => [
        'class' => CurrentUser::class,
        'withSession()' => [Reference::to(SessionInterface::class)],
    ],
];
