<?php

declare(strict_types=1);

namespace App\Domain\Revision;

use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Data access for `page_revisions` (README §8 "Revisions").
 *
 * Autosave writes a draft every few seconds, so draft saves are coalesced: a save by the same
 * author within {@see COALESCE_SECONDS} of the page's latest draft revision updates that revision
 * instead of adding one. Publishing always adds a revision. Only the newest {@see KEEP} revisions
 * of a page are kept.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class RevisionRepository
{
    public const int COALESCE_SECONDS = 600;
    public const int KEEP = 50;

    private const string TABLE = '{{%page_revisions}}';
    private const string USERS_TABLE = '{{%users}}';

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function record(int $pageId, string $kind, int $version, string $document, int $authorId): void
    {
        $now = Clock::now();
        $timestamp = Clock::toStorage($now);

        if ($kind === Revision::DRAFT) {
            $latest = (new Query($this->db))
                ->select(['id', 'kind', 'author_id', 'updated_at'])
                ->from(self::TABLE)
                ->where(['page_id' => $pageId])
                ->orderBy(['id' => SORT_DESC])
                ->limit(1)
                ->one();
            $updatedAt = is_array($latest) ? Clock::fromStorage($latest['updated_at'] ?? null) : null;
            if (
                is_array($latest)
                && ($latest['kind'] ?? null) === Revision::DRAFT
                && (int) ($latest['author_id'] ?? 0) === $authorId
                && $updatedAt !== null
                && $now->getTimestamp() - $updatedAt->getTimestamp() < self::COALESCE_SECONDS
            ) {
                $this->db->createCommand()->update(self::TABLE, [
                    'version' => $version,
                    'document' => $document,
                    'updated_at' => $timestamp,
                ], ['id' => (int) $latest['id']])->execute();

                return;
            }
        }

        $this->db->createCommand()->insert(self::TABLE, [
            'page_id' => $pageId,
            'kind' => $kind,
            'version' => $version,
            'document' => $document,
            'author_id' => $authorId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->execute();

        $this->prune($pageId);
    }

    /**
     * Newest first, without documents.
     *
     * @return list<Revision>
     */
    public function list(int $pageId): array
    {
        $rows = (new Query($this->db))
            ->select(['r.id', 'r.page_id', 'r.kind', 'r.version', 'r.author_id', 'r.created_at', 'r.updated_at', 'u.name AS author_name'])
            ->addSelect(['size' => new Expression('LENGTH(r.document)')])
            ->from(['r' => self::TABLE])
            ->leftJoin(['u' => self::USERS_TABLE], 'r.author_id = u.id')
            ->where(['r.page_id' => $pageId])
            ->orderBy(['r.id' => SORT_DESC])
            ->all();

        $revisions = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $revisions[] = Revision::fromRow($row);
            }
        }

        return $revisions;
    }

    public function find(int $pageId, int $id): ?Revision
    {
        $row = (new Query($this->db))
            ->select(['r.*', 'u.name AS author_name'])
            ->from(['r' => self::TABLE])
            ->leftJoin(['u' => self::USERS_TABLE], 'r.author_id = u.id')
            ->where(['r.page_id' => $pageId, 'r.id' => $id])
            ->one();

        return is_array($row) ? Revision::fromRow($row) : null;
    }

    public function deleteForPage(int $pageId): void
    {
        $this->db->createCommand()->delete(self::TABLE, ['page_id' => $pageId])->execute();
    }

    private function prune(int $pageId): void
    {
        $stale = (new Query($this->db))
            ->select('id')
            ->from(self::TABLE)
            ->where(['page_id' => $pageId])
            ->orderBy(['id' => SORT_DESC])
            ->offset(self::KEEP)
            ->limit(1000)
            ->column();

        if ($stale !== []) {
            $this->db->createCommand()->delete(self::TABLE, ['id' => $stale])->execute();
        }
    }
}
