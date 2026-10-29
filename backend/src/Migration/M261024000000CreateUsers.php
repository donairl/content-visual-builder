<?php

declare(strict_types=1);

namespace App\Migration;

use App\Shared\Db\TableOptions;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

/**
 * Timestamps are `datetime`, not `timestamp`, on purpose: on MariaDB (with the default
 * `explicit_defaults_for_timestamp=OFF`) the first NOT NULL `timestamp` column silently gains
 * `ON UPDATE CURRENT_TIMESTAMP`, and `timestamp` stops at 2038. All values are written in UTC.
 */
final class M261024000000CreateUsers implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%users}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'email' => ColumnBuilder::string(255)->notNull(),
            'name' => ColumnBuilder::string(255)->notNull(),
            'password_hash' => ColumnBuilder::string(255)->notNull(),
            'role' => ColumnBuilder::string(16)->notNull(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        // Emails are stored lowercased by UserRepository, so this index is case-insensitive in effect.
        $b->createIndex(self::TABLE, 'idx_users_email_unique', 'email', 'UNIQUE');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
