<?php

declare(strict_types=1);

namespace App\Domain\Template;

use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Data access for `templates`.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class TemplateRepository
{
    private const string TABLE = '{{%templates}}';
    private const string USERS_TABLE = '{{%users}}';

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Summaries, newest first.
     *
     * @return list<Template>
     */
    public function all(): array
    {
        $templates = [];
        foreach ($this->query()->orderBy(['t.id' => SORT_DESC])->all() as $row) {
            if (is_array($row)) {
                $templates[] = Template::fromRow($row);
            }
        }

        return $templates;
    }

    public function findById(int $id): ?Template
    {
        $row = $this->query()->addSelect(['t.document'])->where(['t.id' => $id])->one();

        return is_array($row) ? Template::fromRow($row) : null;
    }

    public function create(string $name, string $kind, string $document, int $authorId): Template
    {
        $timestamp = Clock::toStorage(Clock::now());
        $keys = $this->db->createCommand()->insertReturningPks(self::TABLE, [
            'name' => $name,
            'kind' => $kind,
            'document' => $document,
            'author_id' => $authorId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->findById((int) ($keys['id'] ?? 0)) ?? throw new \RuntimeException('The template could not be read back after insert.');
    }

    public function rename(int $id, string $name): void
    {
        $this->db->createCommand()->update(self::TABLE, [
            'name' => $name,
            'updated_at' => Clock::toStorage(Clock::now()),
        ], ['id' => $id])->execute();
    }

    public function delete(int $id): bool
    {
        return $this->db->createCommand()->delete(self::TABLE, ['id' => $id])->execute() > 0;
    }

    private function query(): Query
    {
        return (new Query($this->db))
            ->select(['t.id', 't.name', 't.kind', 't.author_id', 't.created_at', 't.updated_at', 'u.name AS author_name'])
            ->from(['t' => self::TABLE])
            ->leftJoin(['u' => self::USERS_TABLE], 't.author_id = u.id');
    }
}
