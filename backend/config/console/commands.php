<?php

declare(strict_types=1);

use App\Console;

return [
    'hello' => Console\HelloCommand::class,
    'user:create' => Console\CreateUserCommand::class,
    'pages:rerender' => Console\RerenderPagesCommand::class,
];
