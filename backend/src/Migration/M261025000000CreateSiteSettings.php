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
 * Site-wide settings as named JSON values, starting with the global styles (README §9). Each value
 * carries a version for compare-and-swap saves, like page drafts.
 */
final class M261025000000CreateSiteSettings implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%site_settings}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'name' => ColumnBuilder::string(64)->notNull(),
            'value' => self::longText($b)->notNull(),
            'version' => ColumnBuilder::integer()->notNull()->defaultValue(1),
            'updated_by' => ColumnBuilder::integer(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        // One row per setting; the index (not application code) stops two first saves racing.
        $b->createIndex(self::TABLE, 'idx_site_settings_name_unique', 'name', 'UNIQUE');
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
