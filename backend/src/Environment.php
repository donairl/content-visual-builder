<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

use function in_array;
use function sprintf;

final class Environment
{
    public const DEV = 'dev';
    public const TEST = 'test';
    public const PROD = 'prod';

    public const ENVIRONMENTS = [
        self::DEV,
        self::TEST,
        self::PROD,
    ];

    public const DB_DRIVERS = ['sqlite', 'pgsql', 'mysql'];

    private static array $values = [];

    public static function prepare(): void
    {
        self::setEnvironment();
        self::setBoolean('APP_C3', false);
        self::setBoolean('APP_DEBUG', false);
        self::setNonEmptyStringOrNull('APP_HOST_PATH', null);
        self::setDbDriver();
        self::setString('DB_PATH', 'runtime/database.sqlite');
        self::setString('DB_HOST', '127.0.0.1');
        self::setNonEmptyStringOrNull('DB_PORT', null);
        self::setString('DB_NAME', 'content_visual_builder');
        self::setString('DB_USER', '');
        self::setString('DB_PASSWORD', '');
        self::setNonEmptyStringOrNull('SESSION_COOKIE_SECURE', null);
    }

    /**
     * @return 'sqlite'|'pgsql'|'mysql'
     */
    public static function dbDriver(): string
    {
        /** @var 'sqlite'|'pgsql'|'mysql' */
        return self::$values['DB_DRIVER'];
    }

    /**
     * SQLite database file. A relative path is resolved against the application root so the web
     * server, the console and the test runner all open the same file regardless of their cwd.
     */
    public static function dbPath(string $rootPath): string
    {
        /** @var string $path */
        $path = self::$values['DB_PATH'];

        if ($path === ':memory:' || str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($rootPath, '/') . '/' . $path;
    }

    public static function dbHost(): string
    {
        /** @var string */
        return self::$values['DB_HOST'];
    }

    /**
     * The port, defaulting to the driver's well-known one.
     */
    public static function dbPort(): string
    {
        /** @var string|null $port */
        $port = self::$values['DB_PORT'];

        return $port ?? (self::dbDriver() === 'mysql' ? '3306' : '5432');
    }

    public static function dbName(): string
    {
        /** @var string */
        return self::$values['DB_NAME'];
    }

    public static function dbUser(): string
    {
        /** @var string */
        return self::$values['DB_USER'];
    }

    public static function dbPassword(): string
    {
        /** @var string */
        return self::$values['DB_PASSWORD'];
    }

    /**
     * Whether the session cookie carries the `Secure` flag.
     *
     * Defaults to on in production only: yiisoft/session throws when a secure cookie is issued over
     * plain HTTP, which is what the dev server and the in-process test requests use.
     */
    public static function sessionCookieSecure(): bool
    {
        /** @var string|null $value */
        $value = self::$values['SESSION_COOKIE_SECURE'];

        if ($value === null) {
            return self::isProd();
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? self::isProd();
    }

    /**
     * @return non-empty-string
     */
    public static function appEnv(): string
    {
        /** @var non-empty-string */
        return self::$values['APP_ENV'];
    }

    public static function isDev(): bool
    {
        return self::appEnv() === self::DEV;
    }

    public static function isTest(): bool
    {
        return self::appEnv() === self::TEST;
    }

    public static function isProd(): bool
    {
        return self::appEnv() === self::PROD;
    }

    /**
     * @return non-empty-string|null
     */
    public static function appHostPath(): ?string
    {
        /** @var non-empty-string|null */
        return self::$values['APP_HOST_PATH'];
    }

    public static function appC3(): bool
    {
        /** @var bool */
        return self::$values['APP_C3'];
    }

    public static function appDebug(): bool
    {
        /** @var bool */
        return self::$values['APP_DEBUG'];
    }

    private static function setEnvironment(): void
    {
        $environment = self::getRawValue('APP_ENV') ?: self::PROD;

        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new RuntimeException(
                sprintf(
                    'APP_ENV="%s" is invalid. Valid values are "%s".',
                    $environment,
                    implode('", "', self::ENVIRONMENTS),
                ),
            );
        }

        self::$values['APP_ENV'] = $environment;
    }

    private static function setDbDriver(): void
    {
        $driver = self::getRawValue('DB_DRIVER') ?: 'sqlite';

        if (!in_array($driver, self::DB_DRIVERS, true)) {
            throw new RuntimeException(
                sprintf(
                    'DB_DRIVER="%s" is invalid. Valid values are "%s".',
                    $driver,
                    implode('", "', self::DB_DRIVERS),
                ),
            );
        }

        self::$values['DB_DRIVER'] = $driver;
    }

    private static function setBoolean(string $key, bool $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null
            ? $default
            : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    private static function setInteger(string $key, int $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null ? $default : (int) $value;
    }

    private static function setString(string $key, string $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value ?? $default;
    }

    private static function setNonEmptyStringOrNull(string $key, ?string $default): void
    {
        $value = self::getRawValue($key);
        self::$values[$key] = $value === null || $value === '' ? $default : $value;
    }

    private static function getRawValue(string $key): ?string
    {
        $value = getenv($key, true);
        if ($value !== false) {
            return $value;
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
    }
}
