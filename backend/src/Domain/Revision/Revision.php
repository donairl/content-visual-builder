<?php

declare(strict_types=1);

namespace App\Domain\Revision;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

/**
 * One entry of a page's revision history. `document` is only loaded when a single revision is
 * read.
 *
 * @psalm-type RevisionSummary = array{
 *     id: int,
 *     kind: string,
 *     version: int,
 *     authorId: int,
 *     authorName: string,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     size: int
 * }
 */
final readonly class Revision
{
    public const string DRAFT = 'draft';
    public const string PUBLISHED = 'published';

    public function __construct(
        public int $id,
        public int $pageId,
        public string $kind,
        public int $version,
        public int $authorId,
        public string $authorName,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public int $size,
        public ?string $document = null,
    ) {}

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            pageId: (int) ($row['page_id'] ?? 0),
            kind: (string) ($row['kind'] ?? self::DRAFT),
            version: (int) ($row['version'] ?? 0),
            authorId: (int) ($row['author_id'] ?? 0),
            authorName: (string) ($row['author_name'] ?? ''),
            createdAt: Clock::fromStorage($row['created_at'] ?? null),
            updatedAt: Clock::fromStorage($row['updated_at'] ?? null),
            size: (int) ($row['size'] ?? 0),
            document: isset($row['document']) ? (string) $row['document'] : null,
        );
    }

    /**
     * @return RevisionSummary
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'version' => $this->version,
            'authorId' => $this->authorId,
            'authorName' => $this->authorName,
            'createdAt' => Clock::toApi($this->createdAt),
            'updatedAt' => Clock::toApi($this->updatedAt),
            'size' => $this->size,
        ];
    }
}
