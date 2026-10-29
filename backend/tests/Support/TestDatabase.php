<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\User\Role;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Environment;
use App\Shared\Db\SqliteDriver;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\Migrator;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Dsn;
use Yiisoft\Security\PasswordHasher;

use function dirname;

/**
 * The SQLite database the functional tests run against, created and migrated in-process with the
 * application's own migrations.
 */
final class TestDatabase
{
    public const PASSWORD = 'correct-horse-battery';

    private static ?ConnectionInterface $connection = null;

    public static function path(): string
    {
        return Environment::dbPath(dirname(__DIR__, 2));
    }

    public static function recreate(): void
    {
        $path = self::path();
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::$connection = null;

        $migrator = new Migrator(self::connection(), new NullMigrationInformer());
        $files = glob(dirname(__DIR__, 2) . '/src/Migration/M*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $class = 'App\\Migration\\' . basename($file, '.php');
            $migration = new $class();
            if ($migration instanceof RevertibleMigrationInterface) {
                $migrator->up($migration);
            }
        }
    }

    public static function connection(): ConnectionInterface
    {
        return self::$connection ??= new Connection(
            new SqliteDriver((string) new Dsn('sqlite', self::path())),
            new SchemaCache(),
        );
    }

    /**
     * Creates a user with {@see PASSWORD} and a unique email.
     */
    public static function createUser(Role $role, string $name = 'Test User'): User
    {
        $email = $role->value . '-' . bin2hex(random_bytes(4)) . '@example.com';

        return (new UserRepository(self::connection(), new PasswordHasher()))
            ->create($name, $email, self::PASSWORD, $role);
    }
}
