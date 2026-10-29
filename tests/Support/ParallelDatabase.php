<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

/**
 * Prepares the per-worker database for parallel test runs (para-test).
 *
 * ParaTest exports PARATEST=1 and a unique TEST_TOKEN per worker process. This
 * class points the worker's DATABASE_URL at its own database (<name>_t<token>)
 * and creates it if it does not exist yet. Every worker then keeps its own
 * schema in that database (see RefreshDatabaseTrait), so concurrent workers
 * never race on the shared schema.
 */
final class ParallelDatabase
{
    public static function boot(): void
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

        if (!is_string($url) || !str_starts_with($url, 'postgres')) {
            return;
        }

        $token = getenv('TEST_TOKEN') ?: '1';
        $database = self::databaseName($url);

        if ($database === '') {
            return;
        }

        $workerDatabase = $database.'_t'.$token;
        $url = self::withDatabaseName($url, $workerDatabase);

        $_SERVER['DATABASE_URL'] = $url;
        $_ENV['DATABASE_URL'] = $url;
        putenv('DATABASE_URL='.$url);

        self::createDatabaseIfMissing($url, $workerDatabase);
    }

    /**
     * Returns the database name the worker database is derived from.
     */
    private static function databaseName(string $url): string
    {
        $queryStart = strpos($url, '?');
        $base = $queryStart === false ? $url : substr($url, 0, $queryStart);
        $slash = strrpos($base, '/');

        if ($slash === false) {
            return '';
        }

        return substr($base, $slash + 1);
    }

    private static function withDatabaseName(string $url, string $database): string
    {
        $queryStart = strpos($url, '?');
        $base = $queryStart === false ? $url : substr($url, 0, $queryStart);
        $query = $queryStart === false ? '' : substr($url, $queryStart);
        $slash = strrpos($base, '/');

        if ($slash === false) {
            return $url;
        }

        return substr($base, 0, $slash + 1).$database.$query;
    }

    private static function createDatabaseIfMissing(string $url, string $database): void
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $parts['host'], $parts['port'] ?? 5432);
        $quoted = '"'.str_replace('"', '""', $database).'"';

        try {
            $pdo = new \PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

            try {
                $pdo->exec('CREATE DATABASE '.$quoted);
            } catch (\PDOException $e) {
                // 42P04: the worker database already exists from an earlier run.
                if ($e->getCode() !== '42P04') {
                    throw $e;
                }
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf('Failed to create the parallel test worker database "%s": %s', $database, $e->getMessage()), previous: $e);
        }
    }
}
