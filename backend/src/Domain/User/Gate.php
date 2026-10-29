<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Page\Page;

/**
 * Server-side authorisation: role capabilities plus page ownership (README §8).
 *
 * Every API action asks the gate; the capability list sent to the editor is only for hiding UI.
 */
final class Gate
{
    /**
     * Whether `$user` may use `$capability`, on `$page` if given.
     *
     * Acting on someone else's page additionally needs `edit_others_pages` (edit, publish) or
     * `delete_others_pages` (delete); on one's own page the base capability is enough.
     */
    public static function can(User $user, Capability $capability, ?Page $page = null): bool
    {
        return self::canOwned($user, $capability, $page?->authorId);
    }

    /**
     * {@see can()} for anything owned by an author (pages, components): `$ownerId` null means no
     * particular resource.
     */
    public static function canOwned(User $user, Capability $capability, ?int $ownerId): bool
    {
        if (!$user->role->has($capability)) {
            return false;
        }

        if ($ownerId === null || $ownerId === $user->id) {
            return true;
        }

        return match ($capability) {
            Capability::EditPages, Capability::PublishPages => $user->role->has(Capability::EditOthersPages),
            Capability::DeletePages => $user->role->has(Capability::DeleteOthersPages),
            default => true,
        };
    }

    /**
     * The capability names the user holds, for `/api/session`.
     *
     * @return list<string>
     */
    public static function capabilityNames(User $user): array
    {
        return array_map(static fn(Capability $c): string => $c->value, $user->role->capabilities());
    }
}
