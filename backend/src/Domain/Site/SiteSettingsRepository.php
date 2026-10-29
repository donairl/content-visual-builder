<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Document\DocumentJson;
use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Data access for `site_settings`: named JSON values with a version.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class SiteSettingsRepository
{
    private const string TABLE = '{{%site_settings}}';

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    public function find(string $name): ?StoredSetting
    {
        $row = (new Query($this->db))
            ->select(['value', 'version', 'updated_at'])
            ->from(self::TABLE)
            ->where(['name' => $name])
            ->one();

        if (!is_array($row)) {
            return null;
        }

        return new StoredSetting(
            DocumentJson::decode((string) ($row['value'] ?? '')),
            (int) ($row['version'] ?? 1),
            Clock::fromStorage($row['updated_at'] ?? null),
        );
    }

    /**
     * Compare-and-swap save of an encoded value. `$baseVersion` 0 creates the row; the unique index
     * makes a racing second create fail, exactly like a version mismatch.
     *
     * @return StoredSetting|null `null` when the stored version is no longer `$baseVersion`.
     */
    public function save(string $name, string $json, int $baseVersion, int $userId): ?StoredSetting
    {
        $now = Clock::now();
        $value = DocumentJson::decode($json);

        if ($baseVersion === 0) {
            try {
                $this->db->createCommand()->insert(self::TABLE, [
                    'name' => $name,
                    'value' => $json,
                    'version' => 1,
                    'updated_by' => $userId,
                    'updated_at' => Clock::toStorage($now),
                ])->execute();
            } catch (IntegrityException) {
                return null;
            }

            return new StoredSetting($value, 1, $now);
        }

        $affected = $this->db->createCommand()->update(self::TABLE, [
            'value' => $json,
            'version' => new Expression('version + 1'),
            'updated_by' => $userId,
            'updated_at' => Clock::toStorage($now),
        ], ['name' => $name, 'version' => $baseVersion])->execute();

        return $affected === 1 ? new StoredSetting($value, $baseVersion + 1, $now) : null;
    }
}
