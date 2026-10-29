<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Clock;
use DateTimeImmutable;
use Yiisoft\Auth\IdentityInterface;

/**
 * A CMS user. Also the session identity: yiisoft/user stores {@see getId()} in the session and
 * hands it back to {@see UserRepository::findIdentity()} on the next request, so a role change in
 * the database applies immediately.
 *
 * The password hash never leaves through {@see toArray()}.
 */
final readonly class User implements IdentityInterface
{
    public function __construct(
        public int $id,
        public string $email,
        public string $name,
        public string $passwordHash,
        public Role $role,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return (string) $this->id;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            email: (string) ($row['email'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            passwordHash: (string) ($row['password_hash'] ?? ''),
            role: Role::fromMixed($row['role'] ?? null),
            createdAt: Clock::fromStorage($row['created_at'] ?? null),
            updatedAt: Clock::fromStorage($row['updated_at'] ?? null),
        );
    }

    /**
     * The shape sent to the editor in `/api/session`.
     *
     * @return array{id: int, name: string, email: string, role: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
        ];
    }
}
