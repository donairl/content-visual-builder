<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\User;

use App\Domain\Page\Page;
use App\Domain\Page\PageStatus;
use App\Domain\User\Capability;
use App\Domain\User\Gate;
use App\Domain\User\Role;
use App\Domain\User\User;
use Codeception\Test\Unit;
use Codeception\Attribute\DataProvider;

use function PHPUnit\Framework\assertSame;

final class GateTest extends Unit
{
    /**
     * README §8 capability table, verbatim.
     */
    private const TABLE = [
        'edit_pages' => ['admin' => true, 'editor' => true, 'author' => true],
        'edit_others_pages' => ['admin' => true, 'editor' => true, 'author' => false],
        'publish_pages' => ['admin' => true, 'editor' => true, 'author' => true],
        'delete_pages' => ['admin' => true, 'editor' => true, 'author' => true],
        'delete_others_pages' => ['admin' => true, 'editor' => true, 'author' => false],
        'upload_files' => ['admin' => true, 'editor' => true, 'author' => true],
        'manage_users' => ['admin' => true, 'editor' => false, 'author' => false],
        'edit_theme_options' => ['admin' => true, 'editor' => true, 'author' => false],
    ];

    #[DataProvider('matrix')]
    public function testCapabilityTable(Role $role, Capability $capability, bool $expected): void
    {
        assertSame($expected, Gate::can(self::user(1, $role), $capability));
    }

    /**
     * @return iterable<string, array{Role, Capability, bool}>
     */
    public static function matrix(): iterable
    {
        foreach (self::TABLE as $capability => $roles) {
            foreach ($roles as $role => $allowed) {
                yield "$role $capability" => [Role::from($role), Capability::from($capability), $allowed];
            }
        }
    }

    public function testTableCoversEveryCapability(): void
    {
        assertSame(array_keys(self::TABLE), array_map(static fn(Capability $c): string => $c->value, Capability::cases()));
    }

    #[DataProvider('ownership')]
    public function testOwnership(Role $role, Capability $capability, bool $ownPage, bool $othersPage): void
    {
        $user = self::user(1, $role);

        assertSame($ownPage, Gate::can($user, $capability, self::page(authorId: 1)), 'own page');
        assertSame($othersPage, Gate::can($user, $capability, self::page(authorId: 2)), "someone else's page");
    }

    /**
     * @return iterable<string, array{Role, Capability, bool, bool}>
     */
    public static function ownership(): iterable
    {
        foreach ([Role::Admin, Role::Editor] as $role) {
            foreach ([Capability::EditPages, Capability::PublishPages, Capability::DeletePages] as $capability) {
                yield $role->value . ' ' . $capability->value => [$role, $capability, true, true];
            }
        }
        yield 'author edit' => [Role::Author, Capability::EditPages, true, false];
        yield 'author publish' => [Role::Author, Capability::PublishPages, true, false];
        yield 'author delete' => [Role::Author, Capability::DeletePages, true, false];
        yield 'author manage users' => [Role::Author, Capability::ManageUsers, false, false];
    }

    public function testCapabilityNamesFollowRole(): void
    {
        assertSame(['edit_pages', 'publish_pages', 'delete_pages', 'upload_files'], Gate::capabilityNames(self::user(1, Role::Author)));
        assertSame(8, count(Gate::capabilityNames(self::user(1, Role::Admin))));
    }

    private static function user(int $id, Role $role): User
    {
        return new User($id, 'u' . $id . '@example.com', 'User ' . $id, '', $role);
    }

    private static function page(int $authorId): Page
    {
        return new Page(10, 'Title', 'title', PageStatus::Draft, $authorId, 'Someone', 1, null, null, null, null, null);
    }
}
