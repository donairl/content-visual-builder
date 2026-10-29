<?php

declare(strict_types=1);

/**
 * Where yiisoft/user finds identities. Registered at the `common` level so console commands
 * resolve the same repository the web stack uses.
 */

use App\Domain\User\UserRepository;
use Yiisoft\Auth\IdentityRepositoryInterface;

return [
    IdentityRepositoryInterface::class => UserRepository::class,
];
