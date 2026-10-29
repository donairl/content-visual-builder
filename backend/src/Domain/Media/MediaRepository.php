<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Shared\Clock;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Data access for `media` — one row per uploaded image.
 */
final readonly class MediaRepository
{
    private const TABLE = '{{%media}}';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * @return int The new row's id.
     */
    public function create(int $userId, string $path, string $mime, int $size, int $width, int $height): int
    {
        $primaryKeys = $this->db->createCommand()->insertReturningPks(self::TABLE, [
            'user_id' => $userId,
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'created_at' => Clock::toStorage(Clock::now()),
        ]);

        return (int) ($primaryKeys['id'] ?? 0);
    }
}
