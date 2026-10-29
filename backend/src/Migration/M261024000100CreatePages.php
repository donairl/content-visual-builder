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
 * Pages keep two independent copies of the document: the draft the editor saves (with a version
 * used for compare-and-swap) and the snapshot taken at publish time, together with the HTML and
 * CSS rendered from it. Editing the draft never touches the live page.
 */
final class M261024000100CreatePages implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const TABLE = '{{%pages}}';

    public function up(MigrationBuilder $b): void
    {
        $b->createTable(self::TABLE, [
            'id' => ColumnBuilder::primaryKey(),
            'title' => ColumnBuilder::string(255)->notNull(),
            'slug' => ColumnBuilder::string(200)->notNull(),
            'status' => ColumnBuilder::string(16)->notNull()->defaultValue('draft'),
            'author_id' => ColumnBuilder::integer()->notNull(),
            'draft_document' => self::longText($b)->notNull(),
            'draft_version' => ColumnBuilder::integer()->notNull()->defaultValue(1),
            'draft_updated_at' => ColumnBuilder::datetime()->notNull(),
            'published_document' => self::longText($b),
            'published_html' => self::longText($b),
            'published_css' => self::longText($b),
            'published_css_hash' => ColumnBuilder::string(64),
            'published_version' => ColumnBuilder::integer(),
            'published_at' => ColumnBuilder::datetime(),
            'created_at' => ColumnBuilder::datetime()->notNull(),
            'updated_at' => ColumnBuilder::datetime()->notNull(),
        ], TableOptions::for($b));

        // The public URL is `/{slug}`: this index, not application code, guarantees one page per URL.
        $b->createIndex(self::TABLE, 'idx_pages_slug_unique', 'slug', 'UNIQUE');
        $b->createIndex(self::TABLE, 'idx_pages_author_id', 'author_id');
        $b->createIndex(self::TABLE, 'idx_pages_status', 'status');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable(self::TABLE);
    }

    /**
     * A text column that holds at least `limits.maxDocumentBytes` (2 MB) plus rendered HTML.
     *
     * yiisoft/db-mysql maps `ColumnBuilder::text()` to plain `text`, which MySQL/MariaDB cap at
     * 64 KB, so it is widened to `longtext` there. PostgreSQL and SQLite `text` are unbounded.
     */
    private static function longText(MigrationBuilder $b): ColumnInterface
    {
        $column = ColumnBuilder::text();

        return $b->getDb()->getDriverName() === 'mysql' ? $column->dbType('longtext') : $column;
    }
}
