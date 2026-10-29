<?php

declare(strict_types=1);

namespace App\Domain\Component;

use App\Domain\Document\DocumentJson;
use App\Domain\Page\DraftSaved;
use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Data access for `components`.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class ComponentRepository
{
    private const string TABLE = '{{%components}}';
    private const string USERS_TABLE = '{{%users}}';
    private const array SUMMARY_COLUMNS = [
        'c.id', 'c.name', 'c.author_id', 'c.draft_version', 'c.draft_updated_at', 'c.published_version',
        'c.published_at', 'c.created_at', 'c.updated_at', 'u.name AS author_name',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * With both documents.
     */
    public function findById(int $id): ?Component
    {
        $row = $this->summaryQuery()
            ->addSelect(['c.draft_document', 'c.published_document'])
            ->where(['c.id' => $id])
            ->one();

        return is_array($row) ? Component::fromRow($row) : null;
    }

    /**
     * Summaries ordered by name.
     *
     * @return list<Component>
     */
    public function all(): array
    {
        $components = [];
        foreach ($this->summaryQuery()->orderBy(['c.name' => SORT_ASC, 'c.id' => SORT_ASC])->all() as $row) {
            if (is_array($row)) {
                $components[] = Component::fromRow($row);
            }
        }

        return $components;
    }

    /**
     * Published documents (decoded) by id, for rendering. `$ids` null means every component.
     *
     * @param list<int>|null $ids
     *
     * @return array<int, mixed>
     */
    public function publishedDocuments(?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $query = (new Query($this->db))->select(['id', 'published_document'])->from(self::TABLE);
        if ($ids !== null) {
            $query->where(['id' => $ids]);
        }

        $documents = [];
        foreach ($query->all() as $row) {
            if (is_array($row)) {
                $documents[(int) ($row['id'] ?? 0)] = DocumentJson::decode((string) ($row['published_document'] ?? ''));
            }
        }

        return $documents;
    }

    public function create(string $name, int $authorId, string $document): Component
    {
        $timestamp = Clock::toStorage(Clock::now());
        $keys = $this->db->createCommand()->insertReturningPks(self::TABLE, [
            'name' => $name,
            'author_id' => $authorId,
            'draft_document' => $document,
            'draft_version' => 1,
            'draft_updated_at' => $timestamp,
            'published_document' => $document,
            'published_version' => 1,
            'published_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->findById((int) ($keys['id'] ?? 0)) ?? throw new \RuntimeException('The component could not be read back after insert.');
    }

    public function rename(int $id, string $name): void
    {
        $this->db->createCommand()->update(self::TABLE, [
            'name' => $name,
            'updated_at' => Clock::toStorage(Clock::now()),
        ], ['id' => $id])->execute();
    }

    /**
     * Compare-and-swap of the draft, like {@see \App\Domain\Page\PageRepository::saveDraft()}.
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
     * Makes the draft at `$version` the published document, provided it is still at that version.
     */
    public function publish(int $id, int $version, string $document): bool
    {
        $timestamp = Clock::toStorage(Clock::now());

        return $this->db->createCommand()->update(self::TABLE, [
            'published_document' => $document,
            'published_version' => $version,
            'published_at' => $timestamp,
            'updated_at' => $timestamp,
        ], ['id' => $id, 'draft_version' => $version])->execute() === 1;
    }

    public function delete(int $id): bool
    {
        return $this->db->createCommand()->delete(self::TABLE, ['id' => $id])->execute() > 0;
    }

    private function summaryQuery(): Query
    {
        return (new Query($this->db))
            ->select(self::SUMMARY_COLUMNS)
            ->from(['c' => self::TABLE])
            ->leftJoin(['u' => self::USERS_TABLE], 'c.author_id = u.id');
    }
}
