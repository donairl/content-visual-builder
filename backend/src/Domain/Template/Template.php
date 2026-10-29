<?php

declare(strict_types=1);

namespace App\Domain\Template;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

/**
 * A saved template (README §11): a whole page or one section, inserted into pages as a copy.
 *
 * @psalm-type TemplateSummary = array{
 *     id: int,
 *     name: string,
 *     kind: string,
 *     authorId: int,
 *     authorName: string,
 *     createdAt: string|null,
 *     updatedAt: string|null
 * }
 */
final readonly class Template
{
    public const string PAGE = 'page';
    public const string SECTION = 'section';
    public const array KINDS = [self::PAGE, self::SECTION];

    public function __construct(
        public int $id,
        public string $name,
        public string $kind,
        public int $authorId,
        public string $authorName,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $document = null,
    ) {}

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            kind: (string) ($row['kind'] ?? self::SECTION),
            authorId: (int) ($row['author_id'] ?? 0),
            authorName: (string) ($row['author_name'] ?? ''),
            createdAt: Clock::fromStorage($row['created_at'] ?? null),
            updatedAt: Clock::fromStorage($row['updated_at'] ?? null),
            document: isset($row['document']) ? (string) $row['document'] : null,
        );
    }

    /**
     * @return TemplateSummary
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'authorId' => $this->authorId,
            'authorName' => $this->authorName,
            'createdAt' => Clock::toApi($this->createdAt),
            'updatedAt' => Clock::toApi($this->updatedAt),
        ];
    }
}
