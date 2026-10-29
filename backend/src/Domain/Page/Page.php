<?php

declare(strict_types=1);

namespace App\Domain\Page;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

/**
 * A page: metadata, the editable draft, and the snapshot rendered at publish time.
 *
 * The document/HTML/CSS columns are only loaded when a query asks for them (list views skip
 * them), hence nullable.
 *
 * @psalm-type PageSummary = array{
 *     id: int,
 *     title: string,
 *     slug: string,
 *     status: string,
 *     authorId: int,
 *     authorName: string,
 *     version: int,
 *     publishedVersion: int|null,
 *     hasUnpublishedChanges: bool,
 *     createdAt: string|null,
 *     updatedAt: string|null,
 *     publishedAt: string|null,
 *     url: string
 * }
 */
final readonly class Page
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public PageStatus $status,
        public int $authorId,
        public string $authorName,
        public int $draftVersion,
        public ?DateTimeImmutable $draftUpdatedAt,
        public ?int $publishedVersion,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public ?string $draftDocument = null,
        public ?string $publishedDocument = null,
        public ?string $publishedHtml = null,
        public ?string $publishedCss = null,
        public ?string $publishedCssHash = null,
    ) {}

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $publishedVersion = $row['published_version'] ?? null;

        return new self(
            id: (int) ($row['id'] ?? 0),
            title: (string) ($row['title'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            status: PageStatus::tryFrom((string) ($row['status'] ?? '')) ?? PageStatus::Draft,
            authorId: (int) ($row['author_id'] ?? 0),
            authorName: (string) ($row['author_name'] ?? ''),
            draftVersion: (int) ($row['draft_version'] ?? 1),
            draftUpdatedAt: Clock::fromStorage($row['draft_updated_at'] ?? null),
            publishedVersion: $publishedVersion === null ? null : (int) $publishedVersion,
            publishedAt: Clock::fromStorage($row['published_at'] ?? null),
            createdAt: Clock::fromStorage($row['created_at'] ?? null),
            updatedAt: Clock::fromStorage($row['updated_at'] ?? null),
            draftDocument: self::nullableString($row['draft_document'] ?? null),
            publishedDocument: self::nullableString($row['published_document'] ?? null),
            publishedHtml: self::nullableString($row['published_html'] ?? null),
            publishedCss: self::nullableString($row['published_css'] ?? null),
            publishedCssHash: self::nullableString($row['published_css_hash'] ?? null),
        );
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    /**
     * True whenever the live page differs from the draft — including a page never published.
     */
    public function hasUnpublishedChanges(): bool
    {
        return $this->publishedVersion !== $this->draftVersion;
    }

    public function url(): string
    {
        return '/' . $this->slug;
    }

    /**
     * `PageSummary` from README §8.
     *
     * @return PageSummary
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'authorId' => $this->authorId,
            'authorName' => $this->authorName,
            'version' => $this->draftVersion,
            'publishedVersion' => $this->publishedVersion,
            'hasUnpublishedChanges' => $this->hasUnpublishedChanges(),
            'createdAt' => Clock::toApi($this->createdAt),
            'updatedAt' => Clock::toApi($this->updatedAt),
            'publishedAt' => Clock::toApi($this->publishedAt),
            'url' => $this->url(),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
