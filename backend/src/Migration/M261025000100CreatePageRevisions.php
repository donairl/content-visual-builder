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
 * Snapshots of page documents for the revision history: one per publish, and one per editing
 * session of draft saves (consecutive saves by the same author are coalesced).
 */
final class M261025000100CreatePageRevisions implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%page_revisions}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'page_id' => ColumnBuilder::integer()->notNull(),
            'kind' => ColumnBuilder::string(16)->notNull(),
            'version' => ColumnBuilder::integer()->notNull(),
            'document' => self::longText($b)->notNull(),
            'author_id' => ColumnBuilder::integer()->notNull(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        $b->createIndex(self::TABLE, 'idx_page_revisions_page_id', ['page_id', 'id']);
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
