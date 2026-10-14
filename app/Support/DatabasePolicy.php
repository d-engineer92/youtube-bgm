<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class DatabasePolicy
{
    public const TEST_DATABASE = 'youtube_bgm_test';

    public const TEST_ROLE = 'bgm_test';

    public const TEST_HOST = 'db-test';

    public const TEST_MARKER = 'youtube-bgm-disposable-test-only-v1';

    public static function assertConfiguration(array $config, bool $testing = false, string $environment = 'local'): void
    {
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Only PostgreSQL is supported.');
        }
        if ($testing && ($environment !== 'testing'
            || ($config['database'] ?? null) !== self::TEST_DATABASE
            || ($config['username'] ?? null) !== self::TEST_ROLE
            || ($config['host'] ?? null) !== self::TEST_HOST
            || (string) ($config['port'] ?? '') !== '5432'
            || isset($config['read']) || isset($config['write']))) {
            throw new RuntimeException('Refusing tests before database initialization: effective connection is outside the explicit test allowlist.');
        }
    }

    public static function assertTestConnection(Connection $connection, string $environment): array
    {
        self::assertConfiguration($connection->getConfig(), true, $environment);
        // Read-only checks occur before any RefreshDatabase migration, transaction or deletion.
        $row = (array) $connection->selectOne("SELECT current_database() AS database, current_user AS role, current_setting('server_version') AS version, current_setting('server_version_num') AS version_num, r.rolsuper, r.rolcreatedb, r.rolcreaterole, shobj_description(d.oid, 'pg_database') AS marker FROM pg_database d JOIN pg_roles r ON r.rolname = current_user WHERE d.datname = current_database()");
        if ($row['database'] !== self::TEST_DATABASE || $row['role'] !== self::TEST_ROLE
            || $row['marker'] !== self::TEST_MARKER || $row['rolsuper'] || $row['rolcreatedb'] || $row['rolcreaterole']) {
            throw new RuntimeException('Refusing tests: PostgreSQL identity, isolated DB marker or restricted test role is invalid.');
        }

        if ($connection->getPDO()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('Refusing tests: effective PDO driver is not PostgreSQL.');
        }
        if (! preg_match('/^x-postgres-image:\s*&postgres-image postgres:(\d+)$/m', file_get_contents(base_path('compose.yaml')), $match)
            || intdiv((int) $row['version_num'], 10000) !== (int) $match[1]) {
            throw new RuntimeException('Refusing tests: PostgreSQL major version differs from the shared Compose setting.');
        }

        return $row;
    }
}
