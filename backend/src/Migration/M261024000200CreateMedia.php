<?php

declare(strict_types=1);

namespace App\Migration;

use App\Shared\Db\TableOptions;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;

final class M261024000200CreateMedia implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%media}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'user_id' => ColumnBuilder::integer()->notNull(),
            'path' => ColumnBuilder::string(255)->notNull(),
            'mime' => ColumnBuilder::string(64)->notNull(),
            'size' => ColumnBuilder::integer()->notNull(),
            'width' => ColumnBuilder::integer()->notNull(),
            'height' => ColumnBuilder::integer()->notNull(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        $b->createIndex(self::TABLE, 'idx_media_path_unique', 'path', 'UNIQUE');
        $b->createIndex(self::TABLE, 'idx_media_user_id', 'user_id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }
}
