<?php

declare(strict_types=1);

namespace App\Domain\Page;

use App\Domain\Render\RenderedPage;
use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

/**
 * Data access for `pages`.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class PageRepository
{
    private const string TABLE = '{{%pages}}';
    private const string USERS_TABLE = '{{%users}}';

    /**
     * Columns of a summary; the large document/HTML/CSS columns are only read when needed.
     */
    private const array SUMMARY_COLUMNS = [
        'p.id', 'p.title', 'p.slug', 'p.status', 'p.author_id', 'p.draft_version', 'p.draft_updated_at',
        'p.published_version', 'p.published_at', 'p.created_at', 'p.updated_at', 'u.name AS author_name',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * A page with its draft document.
     */
    public function findById(int $id): ?Page
    {
        $row = $this->summaryQuery()
            ->addSelect(['p.draft_document'])
            ->where(['p.id' => $id])
            ->one();

        return is_array($row) ? Page::fromRow($row) : null;
    }

    /**
     * A published page with its stored HTML, for the public route.
     */
    public function findPublishedBySlug(string $slug): ?Page
    {
        $row = $this->summaryQuery()
            ->addSelect(['p.published_html', 'p.published_css_hash'])
            ->where(['p.slug' => $slug, 'p.status' => PageStatus::Published->value])
            ->one();

        return is_array($row) ? Page::fromRow($row) : null;
    }

    /**
     * A published page with its stored CSS, for `/assets/page/{id}.{hash}.css`.
     */
    public function findPublishedCss(int $id): ?Page
    {
        $row = $this->summaryQuery()
            ->addSelect(['p.published_css', 'p.published_css_hash'])
            ->where(['p.id' => $id, 'p.status' => PageStatus::Published->value])
            ->one();

        return is_array($row) ? Page::fromRow($row) : null;
    }

    /**
     * Summaries, most recently updated first.
     *
     * @param int|null $authorId Restrict to one author's pages.
     * @param string|null $search Case-insensitive substring of the title or slug.
     *
     * @return list<Page>
     */
    public function search(?int $authorId = null, ?string $search = null, ?PageStatus $status = null): array
    {
        $query = $this->summaryQuery();

        if ($authorId !== null) {
            $query->andWhere(['p.author_id' => $authorId]);
        }
        if ($status !== null) {
            $query->andWhere(['p.status' => $status->value]);
        }
        $search = $search === null ? '' : trim($search);
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['LIKE', 'p.title', $search, 'caseSensitive' => false],
                ['LIKE', 'p.slug', $search, 'caseSensitive' => false],
            ]);
        }

        $pages = [];
        foreach ($query->orderBy(['p.updated_at' => SORT_DESC, 'p.id' => SORT_DESC])->all() as $row) {
            if (is_array($row)) {
                $pages[] = Page::fromRow($row);
            }
        }

        return $pages;
    }

    /**
     * Ids of every published page, for `pages:rerender`.
     *
     * @return list<int>
     */
    public function publishedIds(): array
    {
        $ids = [];
        foreach ((new Query($this->db))->select('id')->from(self::TABLE)->where(['status' => PageStatus::Published->value])->column() as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * The stored publish snapshot (document as published) of a page.
     */
    public function findPublishedDocument(int $id): ?Page
    {
        $row = $this->summaryQuery()
            ->addSelect(['p.published_document'])
            ->where(['p.id' => $id, 'p.status' => PageStatus::Published->value])
            ->one();

        return is_array($row) ? Page::fromRow($row) : null;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $query = (new Query($this->db))->from(self::TABLE)->where(['slug' => $slug]);
        if ($exceptId !== null) {
            $query->andWhere(['<>', 'id', $exceptId]);
        }

        return $query->exists();
    }

    public function create(string $title, string $slug, int $authorId, string $draftDocument): Page
    {
        $timestamp = Clock::toStorage(Clock::now());

        $primaryKeys = $this->db->createCommand()->insertReturningPks(self::TABLE, [
            'title' => $title,
            'slug' => $slug,
            'status' => PageStatus::Draft->value,
            'author_id' => $authorId,
            'draft_document' => $draftDocument,
            'draft_version' => 1,
            'draft_updated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $page = $this->findById((int) ($primaryKeys['id'] ?? 0));
        if ($page === null) {
            throw new \RuntimeException('The page could not be read back after insert.');
        }

        return $page;
    }

    public function updateMeta(int $id, string $title, string $slug): void
    {
        $this->db->createCommand()->update(self::TABLE, [
            'title' => $title,
            'slug' => $slug,
            'updated_at' => Clock::toStorage(Clock::now()),
        ], ['id' => $id])->execute();
    }

    /**
     * Atomic compare-and-swap of the draft: succeeds only if nobody saved since `$baseVersion`.
     *
     * The version check lives in the `WHERE` clause of a single `UPDATE`, so two concurrent saves
     * cannot both win, on any of the three databases.
     *
     * @return DraftSaved|null `null` when the stored version is no longer `$baseVersion`.
     */
    public function saveDraft(int $id, int $baseVersion, string $document): ?DraftSaved
    {
        $now = Clock::now();
        $timestamp = Clock::toStorage($now);

        $affected = $this->db->createCommand()->update(self::TABLE, [
            'draft_document' => $document,
            'draft_version' => new Expression('draft_version + 1'),
            'draft_updated_at' => $timestamp,
            'updated_at' => $timestamp,
        ], ['id' => $id, 'draft_version' => $baseVersion])->execute();

        return $affected === 1 ? new DraftSaved($baseVersion + 1, $now) : null;
    }

    /**
     * Stores a publish snapshot, provided the draft is still at `$version`.
     *
     * @return bool `false` when the draft moved on while rendering.
     */
    public function publish(int $id, int $version, string $document, RenderedPage $rendered): bool
    {
        return $this->db->transaction(function (ConnectionInterface $db) use ($id, $version, $document, $rendered): bool {
            $timestamp = Clock::toStorage(Clock::now());

            return $db->createCommand()->update(self::TABLE, [
                'status' => PageStatus::Published->value,
                'published_document' => $document,
                'published_html' => $rendered->html,
                'published_css' => $rendered->css,
                'published_css_hash' => $rendered->cssHash,
                'published_version' => $version,
                'published_at' => $timestamp,
                'updated_at' => $timestamp,
            ], ['id' => $id, 'draft_version' => $version])->execute() === 1;
        });
    }

    /**
     * Replaces the rendered output of an already published page (after a renderer or base CSS
     * change), keeping its published version and date.
     */
    public function updatePublishedRender(int $id, RenderedPage $rendered): void
    {
        $this->db->createCommand()->update(self::TABLE, [
            'published_html' => $rendered->html,
            'published_css' => $rendered->css,
            'published_css_hash' => $rendered->cssHash,
        ], ['id' => $id, 'status' => PageStatus::Published->value])->execute();
    }

    /**
     * Takes the page offline and drops the snapshot; publishing again renders the current draft.
     */
    public function unpublish(int $id): void
    {
        $this->db->createCommand()->update(self::TABLE, [
            'status' => PageStatus::Draft->value,
            'published_document' => null,
            'published_html' => null,
            'published_css' => null,
            'published_css_hash' => null,
            'published_version' => null,
            'published_at' => null,
            'updated_at' => Clock::toStorage(Clock::now()),
        ], ['id' => $id])->execute();
    }

    public function delete(int $id): bool
    {
        return $this->db->createCommand()->delete(self::TABLE, ['id' => $id])->execute() > 0;
    }

    private function summaryQuery(): Query
    {
        return (new Query($this->db))
            ->select(self::SUMMARY_COLUMNS)
            ->from(['p' => self::TABLE])
            ->leftJoin(['u' => self::USERS_TABLE], 'p.author_id = u.id');
    }
}
