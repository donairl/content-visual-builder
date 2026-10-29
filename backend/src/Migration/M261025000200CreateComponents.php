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
 * Reusable components (README §10). Like pages, a component keeps an editable draft and the
 * published document that every instance on every page renders.
 */
final class M261025000200CreateComponents implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%components}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'name' => ColumnBuilder::string(120)->notNull(),
            'author_id' => ColumnBuilder::integer()->notNull(),
            'draft_document' => self::longText($b)->notNull(),
            'draft_version' => ColumnBuilder::integer()->notNull()->defaultValue(1),
            'draft_updated_at' => ColumnBuilder::datetime()->notNull(),
            'published_document' => self::longText($b)->notNull(),
            'published_version' => ColumnBuilder::integer()->notNull(),
            'published_at' => ColumnBuilder::datetime()->notNull(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        $b->createIndex(self::TABLE, 'idx_components_author_id', 'author_id');
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
