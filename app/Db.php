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
     */
    private static function ensureSchema(PDO $pdo): void
    {
        $schemaPath = __DIR__ . '/../database/schema.sql';
        if (!is_readable($schemaPath)) {
            return;
        }

        $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tasks'")->fetch();
        if ($row !== false) {
            return;
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
