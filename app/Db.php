<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite PDO singleton with WAL mode + idempotent schema bootstrap.
 */
final class Db
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = Config::all();
        $path   = $config['db_path'];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create database directory: {$dir}");
            }
        }

        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$instance = $pdo;
        self::ensureSchema($pdo);

        return $pdo;
    }

    /**
     * Apply schema.sql on first run. Safe to call repeatedly (uses CREATE IF NOT EXISTS).
     *
     * Detects pre-multi-user databases (tasks exists but users does not) and refuses
     * to continue, since adding user_id columns to existing rows is not safe to do
     * automatically. Operator must delete data/app.db (and re-register) to migrate.
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $schemaPath = __DIR__ . '/../database/schema.sql';
        if (!is_readable($schemaPath)) {
            return;
        }

        $hasUsers = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch() !== false;
        if ($hasUsers) {
            return;
        }

        $hasTasks = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tasks'")->fetch() !== false;
        if ($hasTasks) {
            throw new RuntimeException(
                "Database schema is from a pre-multi-user version. "
                . "Delete data/app.db (and any *.db-wal / *.db-shm) and restart the app to recreate the schema."
            );
        }

        $sql = (string) file_get_contents($schemaPath);
        $pdo->exec($sql);
    }

    /**
     * Generate a millisecond unix timestamp.
     */
    public static function now(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Helper to fetch a single row by id.
     */
    public static function findById(string $table, string $id): ?array
    {
        $allowed = ['tasks', 'notes', 'attachments'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException("Table not allowed: {$table}");
        }
        $stmt = self::pdo()->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Like findById but also scoped to a user_id. Returns null when the row
     * doesn't exist OR exists but belongs to another user — controllers
     * should treat both cases as 404 to avoid leaking which is which.
     */
    public static function findByIdForUser(string $table, string $id, string $userId): ?array
    {
        $allowed = ['tasks', 'notes', 'attachments'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException("Table not allowed: {$table}");
        }
        $stmt = self::pdo()->prepare("SELECT * FROM {$table} WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
