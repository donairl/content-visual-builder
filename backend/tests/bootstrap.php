<?php

declare(strict_types=1);

use App\Environment;
use App\Tests\Support\TestDatabase;

/*
 * Tests always run as APP_ENV=test against a throw-away SQLite file, migrated from scratch on every
 * run so that each run starts from the same empty schema. Set through putenv() so that both
 * Environment::prepare() and anything re-reading the environment (EnvironmentTest) agree.
 */
$defaults = [
    'APP_ENV' => 'test',
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => 'runtime/test.sqlite',
];
foreach ($defaults as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

Environment::prepare();
TestDatabase::recreate();
