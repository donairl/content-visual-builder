<?php

declare(strict_types=1);

namespace App\Shared\Db;

use Yiisoft\Db\Migration\MigrationBuilder;

/**
 * Driver-specific `CREATE TABLE` options for the migrations.
 */
final class TableOptions
{
    /**
     * MySQL/MariaDB pick the server's default charset when none is given, which on many MariaDB
     * installs is still latin1 — four-byte UTF-8 (emoji in a heading) would then be rejected or
     * mangled. PostgreSQL and SQLite store UTF-8 without options.
     */
    public static function for(MigrationBuilder $b): ?string
    {
        return $b->getDb()->getDriverName() === 'mysql'
            ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : null;
    }
}
