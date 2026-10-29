<?php

declare(strict_types=1);

namespace App\Migration;

use App\Shared\Db\TableOptions;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;
use Yiisoft\Db\Schema\Column\ColumnBuilder;
use Yiisoft\Db\Schema\Column\ColumnInterface;

/**
 * The template library (README §11): saved sections and pages, copied into a page on insert.
 */
final class M261025000300CreateTemplates implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%templates}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'name' => ColumnBuilder::string(120)->notNull(),
            'kind' => ColumnBuilder::string(16)->notNull(),
            'document' => self::longText($b)->notNull(),
            'author_id' => ColumnBuilder::integer()->notNull(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        $b->createIndex(self::TABLE, 'idx_templates_author_id', 'author_id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }

    /**
     * See {@see M261024000100CreatePages::longText()}: MySQL's plain `text` is capped at 64 KB.
     */
    private static function longText(MigrationBuilder $b): ColumnInterface
    {
        $column = ColumnBuilder::text();

        return $b->getDb()->getDriverName() === 'mysql' ? $column->dbType('longtext') : $column;
    }
}
