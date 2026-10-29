<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Roles grant capabilities (README §8). Backing values are what `users.role` stores.
 */
enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';

    /**
     * The capability table. Ownership ("own pages only" for authors) is not a capability of its
     * own: authors simply lack the `*_others_*` ones, and {@see Gate} applies that per page.
     *
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Admin => Capability::cases(),
            self::Editor => [
                Capability::EditPages,
                Capability::EditOthersPages,
                Capability::PublishPages,
                Capability::DeletePages,
                Capability::DeleteOthersPages,
                Capability::UploadFiles,
                Capability::EditThemeOptions,
            ],
            self::Author => [
                Capability::EditPages,
                Capability::PublishPages,
                Capability::DeletePages,
                Capability::UploadFiles,
            ],
        };
    }

    public function has(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $role): string => $role->value, self::cases());
    }

    /**
     * Resolves a stored value, falling back to the least privileged role.
     */
    public static function fromMixed(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Author) : self::Author;
    }
}
