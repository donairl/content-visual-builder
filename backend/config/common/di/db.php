<?php

declare(strict_types=1);

/**
 * Database connection, switched on `DB_DRIVER` (sqlite | pgsql | mysql).
 *
 * All three share the same migrations and query-builder code; only the connection differs. MariaDB
 * uses the `mysql` driver. Values are read through {@see \App\Environment} only.
 */

use App\Environment;
use App\Shared\Db\SqliteDriver;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Mysql;
use Yiisoft\Db\Pgsql;
use Yiisoft\Db\Sqlite;
use Yiisoft\Definitions\Reference;

$root = dirname(__DIR__, 3);

$definition = match (Environment::dbDriver()) {
    'sqlite' => [
        'class' => Sqlite\Connection::class,
        'driver' => new SqliteDriver((string) new Sqlite\Dsn('sqlite', Environment::dbPath($root))),
    ],
    // The host is always explicit: an omitted host makes libpq fall back to a Unix socket that is
    // not served on every machine.
    'pgsql' => [
        'class' => Pgsql\Connection::class,
        'driver' => new Pgsql\Driver(
            new Pgsql\Dsn('pgsql', Environment::dbHost(), Environment::dbName(), Environment::dbPort()),
            Environment::dbUser(),
            Environment::dbPassword(),
        ),
    ],
    'mysql' => [
        'class' => Mysql\Connection::class,
        'driver' => (static function (): Mysql\Driver {
            $driver = new Mysql\Driver(
                new Mysql\Dsn('mysql', Environment::dbHost(), Environment::dbName(), Environment::dbPort()),
                Environment::dbUser(),
                Environment::dbPassword(),
            );
            // Documents are UTF-8 JSON; utf8mb4 is the only MySQL charset that holds all of Unicode.
            $driver->charset('utf8mb4');

            return $driver;
        })(),
    ],
};

return [
    ConnectionInterface::class => [
        'class' => $definition['class'],
        '__construct()' => [
            'driver' => $definition['driver'],
            'schemaCache' => Reference::to(SchemaCache::class),
        ],
    ],
];
