<?php

declare(strict_types=1);

use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$basePath = dirname(__DIR__);
Env::load($basePath);

$pretend = in_array('--pretend', $argv, true);
$rollback = in_array('--rollback', $argv, true);

try {
    $pdo = connectForMigrations();
    ensureMigrationsTable($pdo);

    if ($rollback) {
        rollBackMigrations($pdo, $basePath, rollbackSteps($argv), $pretend);
        exit(0);
    }

    $appliedMigrations = appliedMigrations($pdo);
    $migrationFiles = glob($basePath . '/database/migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_NATURAL);

    $pendingMigrations = array_values(array_filter(
        $migrationFiles,
        static fn (string $file): bool => !isset($appliedMigrations[basename($file)])
    ));

    if ($pendingMigrations === []) {
        fwrite(STDOUT, "No pending migrations.\n");
        exit(0);
    }

    foreach ($pendingMigrations as $migrationFile) {
        $migrationName = basename($migrationFile);

        if ($pretend) {
            fwrite(STDOUT, "Would apply {$migrationName}\n");
            continue;
        }

        $sql = trim((string) file_get_contents($migrationFile));

        if ($sql === '') {
            fwrite(STDOUT, "Skipping empty migration {$migrationName}\n");
            continue;
        }

        fwrite(STDOUT, "Applying {$migrationName}...\n");

        try {
            $pdo->exec($sql);

            $statement = $pdo->prepare('INSERT INTO migrations (name) VALUES (:name)');
            $statement->execute(['name' => $migrationName]);

            fwrite(STDOUT, "Applied {$migrationName}\n");
        } catch (\Throwable $exception) {
            fwrite(STDERR, "Migration failed for {$migrationName}: {$exception->getMessage()}\n");
            exit(1);
        }
    }

    if ($pretend) {
        fwrite(STDOUT, 'Pending migrations: ' . count($pendingMigrations) . "\n");
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

function connectForMigrations(): \PDO
{
    $host = (string) Env::get('DB_HOST', '127.0.0.1');
    $port = (string) Env::get('DB_PORT', '3306');
    $name = (string) Env::get('DB_DATABASE');
    $user = (string) Env::get('DB_USERNAME');
    $pass = (string) Env::get('DB_PASSWORD');
    $charset = (string) Env::get('DB_CHARSET', 'utf8mb4');

    if ($name === '') {
        throw new \RuntimeException('DB_DATABASE must be set before running migrations.');
    }

    $databaseDsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    $serverDsn = "mysql:host={$host};port={$port};charset={$charset}";

    try {
        return createPdo($databaseDsn, $user, $pass);
    } catch (\PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'Unknown database')) {
            throw new \RuntimeException('Could not connect to the configured database.');
        }
    }

    try {
        $serverConnection = createPdo($serverDsn, $user, $pass);
    } catch (\PDOException $exception) {
        throw new \RuntimeException('Could not connect to MySQL server to create the database.');
    }

    $databaseName = str_replace('`', '``', $name);
    $serverConnection->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");

    return createPdo($databaseDsn, $user, $pass);
}

function createPdo(string $dsn, string $user, string $pass): \PDO
{
    return new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function ensureMigrationsTable(\PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * php database/migrate.php --rollback [--step=N] [--pretend]
 *
 * Undoes the last N applied migrations, newest first (default 1). Each one needs a file of
 * the same name in database/migrations/down/. That folder sits below migrations/ so the
 * *.sql globs that apply migrations (here, scripts/setup_test_db.php and tests/TestCase.php)
 * never pick a down file up as a forward migration. Keel's own 001-010 have no down files,
 * so a rollback stops at the first of those rather than guessing.
 */
function rollBackMigrations(\PDO $pdo, string $basePath, int $steps, bool $pretend): void
{
    $statement = $pdo->query('SELECT name FROM migrations ORDER BY id DESC LIMIT ' . $steps);
    $migrationNames = $statement->fetchAll(\PDO::FETCH_COLUMN);

    if ($migrationNames === []) {
        fwrite(STDOUT, "Nothing to roll back.\n");
        return;
    }

    // Check every down file first, so a missing one never leaves a half-finished rollback.
    foreach ($migrationNames as $migrationName) {
        if (!is_file(downMigrationPath($basePath, $migrationName))) {
            throw new \RuntimeException("No rollback file for {$migrationName} (expected database/migrations/down/{$migrationName}). Nothing was rolled back.");
        }
    }

    foreach ($migrationNames as $migrationName) {
        if ($pretend) {
            fwrite(STDOUT, "Would roll back {$migrationName}\n");
            continue;
        }

        $sql = trim((string) file_get_contents(downMigrationPath($basePath, $migrationName)));

        fwrite(STDOUT, "Rolling back {$migrationName}...\n");

        try {
            if ($sql !== '') {
                $pdo->exec($sql);
            }

            $delete = $pdo->prepare('DELETE FROM migrations WHERE name = :name');
            $delete->execute(['name' => $migrationName]);

            fwrite(STDOUT, "Rolled back {$migrationName}\n");
        } catch (\Throwable $exception) {
            fwrite(STDERR, "Rollback failed for {$migrationName}: {$exception->getMessage()}\n");
            exit(1);
        }
    }
}

function rollbackSteps(array $argv): int
{
    foreach ($argv as $argument) {
        if (!str_starts_with($argument, '--step=')) {
            continue;
        }

        $steps = filter_var(substr($argument, 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($steps === false) {
            throw new \RuntimeException('--step must be a whole number of 1 or more.');
        }

        return $steps;
    }

    return 1;
}

function downMigrationPath(string $basePath, string $migrationName): string
{
    return $basePath . '/database/migrations/down/' . basename($migrationName);
}

function appliedMigrations(\PDO $pdo): array
{
    $statement = $pdo->query('SELECT name FROM migrations');
    $migrations = [];

    foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $migrationName) {
        $migrations[(string) $migrationName] = true;
    }

    return $migrations;
}