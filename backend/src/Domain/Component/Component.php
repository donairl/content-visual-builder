<?php

declare(strict_types=1);

namespace App\Domain\Component;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

/**
 * A reusable component (README §10): a draft being edited and the published document its instances
 * render. A component is published from the moment it is created.
 *
 * @psalm-type ComponentSummary = array{
 *     id: int,
 *     name: string,
 *     authorId: int,
 *     authorName: string,
 *     version: int,
 *     publishedVersion: int,
 *     hasUnpublishedChanges: bool,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     publishedAt: string|null
 * }
 */
final readonly class Component
{
    public function __construct(
        public int $id,
        public string $name,
        public int $authorId,
        public string $authorName,
        public int $draftVersion,
        public ?DateTimeImmutable $draftUpdatedAt,
        public int $publishedVersion,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $draftDocument = null,
        public ?string $publishedDocument = null,
    ) {}

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            name: (string) ($row['name'] ?? ''),
            authorId: (int) ($row['author_id'] ?? 0),
            authorName: (string) ($row['author_name'] ?? ''),
            draftVersion: (int) ($row['draft_version'] ?? 1),
            draftUpdatedAt: Clock::fromStorage($row['draft_updated_at'] ?? null),
            publishedVersion: (int) ($row['published_version'] ?? 1),
            publishedAt: Clock::fromStorage($row['published_at'] ?? null),
            createdAt: Clock::fromStorage($row['created_at'] ?? null),
            updatedAt: Clock::fromStorage($row['updated_at'] ?? null),
            draftDocument: isset($row['draft_document']) ? (string) $row['draft_document'] : null,
            publishedDocument: isset($row['published_document']) ? (string) $row['published_document'] : null,
        );
    }

    /**
     * @return ComponentSummary
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'authorId' => $this->authorId,
            'authorName' => $this->authorName,
            'version' => $this->draftVersion,
            'publishedVersion' => $this->publishedVersion,
            'hasUnpublishedChanges' => $this->publishedVersion !== $this->draftVersion,
            'createdAt' => Clock::toApi($this->createdAt),
            'updatedAt' => Clock::toApi($this->updatedAt),
            'publishedAt' => Clock::toApi($this->publishedAt),
        ];
    }
}
