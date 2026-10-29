<?php

namespace StrontiumCorp\LaravelMfa\Tests;

use Dotenv\Dotenv;
use PDO;
use Pdo\Mysql;

/**
 * The suite against a real MySQL, MariaDB or PostgreSQL server instead of
 * SQLite, for what only a server does: column defaults it adds on its own,
 * row locks, the casing of information_schema columns, and every migration on
 * each. Skipped unless MFA_TEST_DB_HOST is set, in the environment or in a
 * gitignored `.env.test-db` at the package root (`make test-db`; CI's
 * database jobs). MFA_TEST_DB_DRIVER picks the server: mysql (default, also
 * for MariaDB) or pgsql.
 * A MySQL connection turns explicit_defaults_for_timestamp off, as it is on
 * MariaDB before 10.10 and MySQL 5.7, where a NOT NULL `timestamp` without a
 * default silently gets ON UPDATE CURRENT_TIMESTAMP.
 */
abstract class DatabaseServerTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! self::env('MFA_TEST_DB_HOST')) {
            $this->markTestSkipped('Set MFA_TEST_DB_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run against a database server.');
        }

        // One database for every worker: parallel runs would rebuild it under each other.
        if (getenv('TEST_TOKEN') !== false) {
            $this->markTestSkipped('Runs one process at a time: make test-db.');
        }

        parent::setUp();
    }

    /** For what only MySQL and MariaDB have (information_schema `extra`, `modify`). */
    protected function onlyOnMysql(): void
    {
        if (self::driver() !== 'mysql') {
            $this->markTestSkipped('MySQL and MariaDB only.');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $connection = [
            'driver' => self::driver(),
            'host' => self::env('MFA_TEST_DB_HOST'),
            'database' => self::env('MFA_TEST_DB_DATABASE') ?: 'laravel_mfa_test',
            'username' => self::env('MFA_TEST_DB_USERNAME') ?: (self::driver() === 'pgsql' ? 'postgres' : 'root'),
            'password' => self::env('MFA_TEST_DB_PASSWORD') ?: '',
            'prefix' => '',
        ];

        if (self::driver() === 'pgsql') {
            $app['config']->set('database.connections.server', $connection + [
                'port' => self::env('MFA_TEST_DB_PORT') ?: '5432',
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        } else {
            // PHP 8.5 deprecates the PDO constant for the driver-specific one.
            $initCommand = class_exists(Mysql::class) ? Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;

            $app['config']->set('database.connections.server', $connection + [
                'port' => self::env('MFA_TEST_DB_PORT') ?: '3306',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'options' => [$initCommand => 'SET SESSION explicit_defaults_for_timestamp = 0'],
            ]);
        }
        $app['config']->set('database.default', 'server');
    }

    private static function driver(): string
    {
        return self::env('MFA_TEST_DB_DRIVER') === 'pgsql' ? 'pgsql' : 'mysql';
    }

    private static function env(string $key): ?string
    {
        static $file = null;
        $file ??= is_file($path = __DIR__.'/../.env.test-db') ? Dotenv::parse((string) file_get_contents($path)) : [];
        $value = getenv($key);

        return $value !== false && $value !== '' ? $value : ($file[$key] ?? null);
    }
}
