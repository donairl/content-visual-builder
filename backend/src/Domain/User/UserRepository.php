<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Clock;
use SensitiveParameter;
use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Security\PasswordHasher;

/**
 * Data access for `users`, and the identity source for yiisoft/user.
 *
 * Plain passwords only enter through {@see create()} and {@see authenticate()} and are hashed or
 * verified immediately; only `password_hash` is ever written.
 *
 * @psalm-suppress ClassMustBeFinal Deliberately extensible so it can be doubled in tests.
 */
class UserRepository implements IdentityRepositoryInterface
{
    private const string TABLE = '{{%users}}';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PasswordHasher $passwordHasher,
    ) {}

    /**
     * Restores the session identity. The id arrives from the session as a string.
     */
    public function findIdentity(string $id): ?IdentityInterface
    {
        return ctype_digit($id) ? $this->findById((int) $id) : null;
    }

    public function findById(int $id): ?User
    {
        return $this->fetchOne(['id' => $id]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->fetchOne(['email' => self::normalizeEmail($email)]);
    }

    /**
     * Returns the user only on an exact match, `null` for both "no such email" and "wrong password".
     *
     * A hash comparison runs even when the email is unknown, so response timing does not reveal
     * which addresses have accounts.
     */
    public function authenticate(
        string $email,
        #[SensitiveParameter]
        string $password,
    ): ?User {
        $user = $email === '' ? null : $this->findByEmail($email);
        $hash = $user !== null && $user->passwordHash !== ''
            ? $user->passwordHash
            : $this->passwordHasher->hash('timing-equalizer');

        $valid = $password !== '' && $this->passwordHasher->validate($password, $hash);

        return $valid ? $user : null;
    }

    public function emailExists(string $email): bool
    {
        return $this->query()->where(['email' => self::normalizeEmail($email)])->exists();
    }

    public function create(
        string $name,
        string $email,
        #[SensitiveParameter]
        string $password,
        Role $role,
    ): User {
        $now = Clock::now();
        $timestamp = Clock::toStorage($now);
        $hash = $this->passwordHasher->hash($password);

        $primaryKeys = $this->db->createCommand()->insertReturningPks(self::TABLE, [
            'email' => self::normalizeEmail($email),
            'name' => $name,
            'password_hash' => $hash,
            'role' => $role->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return new User(
            id: (int) ($primaryKeys['id'] ?? 0),
            email: self::normalizeEmail($email),
            name: $name,
            passwordHash: $hash,
            role: $role,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Addresses are stored and compared lowercased, so `A@b.com` and `a@b.com` cannot become two
     * accounts despite the unique index being case-sensitive on some drivers.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function query(): Query
    {
        return (new Query($this->db))->from(self::TABLE);
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function fetchOne(array $condition): ?User
    {
        $row = $this->query()->where($condition)->one();

        return is_array($row) ? User::fromRow($row) : null;
    }
}
