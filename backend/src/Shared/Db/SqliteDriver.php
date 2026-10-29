<?php

declare(strict_types=1);

namespace App\Shared\Db;

use PDO;
use Yiisoft\Db\Driver\Pdo\AbstractPdoDriver;

/**
 * SQLite PDO driver that tunes every new connection for a small multi-process web app.
 *
 * yiisoft/db-sqlite's own driver is final and offers no "after connect" hook, so this mirrors its
 * attribute defaults and adds the pragmas:
 *
 * - `busy_timeout`: concurrent writers (two editors autosaving) wait for the lock instead of failing
 *   immediately with "database is locked".
 * - `journal_mode=WAL`: readers (public pages) never block on a writer (draft saves). The mode is
 *   persistent in the file, so re-issuing it per connection is a cheap no-op after the first time.
 * - `foreign_keys=ON`: SQLite leaves them off by default; harmless while the schema has none, but it
 *   keeps behaviour aligned with PostgreSQL/MariaDB if one is added.
 */
final class SqliteDriver extends AbstractPdoDriver
{
    private const BUSY_TIMEOUT_MS = 5000;

    public function createConnection(): PDO
    {
        $this->attributes += [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_TIMEOUT => intdiv(self::BUSY_TIMEOUT_MS, 1000),
        ];

        $pdo = parent::createConnection();
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('PRAGMA foreign_keys = ON');

        if (!str_contains($this->dsn, ':memory:')) {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }
}
