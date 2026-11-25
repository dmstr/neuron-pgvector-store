<?php

// file generated with AI assistance: Claude Code - [2026-11-25 15:33:32 UTC]

declare(strict_types=1);

namespace Dmstr\NeuronPgvectorStore\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * PDO connection to the PostgreSQL server of the integration tests,
 * configured by the libpq environment variables PGHOST (required),
 * PGPORT (default 5432), PGDATABASE (default postgres), PGUSER (default
 * postgres) and PGPASSWORD (default empty). The server needs the pgvector
 * extension available; the tests create it with
 * `CREATE EXTENSION IF NOT EXISTS vector`.
 *
 * Without PGHOST the calling test is skipped, so the integration suite
 * does not fail on a machine without a database; CI runs it with
 * `--fail-on-skipped`.
 */
final class PostgresConnection
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = self::env('PGHOST');
        if ($host === null) {
            TestCase::markTestSkipped('Set PGHOST (and PGPORT, PGDATABASE, PGUSER, PGPASSWORD) to run the integration tests against PostgreSQL with pgvector.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $host,
            self::env('PGPORT') ?? '5432',
            self::env('PGDATABASE') ?? 'postgres',
        );

        self::$pdo = new \PDO($dsn, self::env('PGUSER') ?? 'postgres', self::env('PGPASSWORD') ?? '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return self::$pdo;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }
}
