<?php declare(strict_types=1);

namespace Rxn\Framework\Data;

use Rxn\Framework\Error\DatabaseException;
use Rxn\Framework\Error\QueryException;

/**
 * Minimal, file-based schema migration runner.
 *
 * A migration is a plain `.sql` file named `NNNN_description.sql`
 * where NNNN is any zero-padded integer prefix (for example
 * `0001_create_users.sql`). Files are executed in lexicographic
 * order against the configured Database, and applied filenames are
 * recorded in a `rxn_migrations` table so subsequent runs are
 * idempotent.
 */
class Migration
{
    const TABLE = 'rxn_migrations';

    /**
     * @var Database
     */
    private $database;

    /**
     * @var string
     */
    private $directory;

    public function __construct(Database $database, string $directory, bool $ensureTable = true)
    {
        if (!is_dir($directory)) {
            throw new DatabaseException("Migration directory does not exist: $directory", 500);
        }
        $this->database  = $database;
        $this->directory = rtrim($directory, '/');
        if ($ensureTable) {
            $this->ensureTable();
        }
    }

    /**
     * Apply every pending migration in order. Returns the filenames
     * that were executed this run.
     *
     * @return string[]
     */
    public function run(): array
    {
        $applied = $this->applied();
        $pending = array_values(array_diff($this->available(), $applied));
        sort($pending);

        $executed = [];
        foreach ($pending as $file) {
            $this->apply($file);
            $executed[] = $file;
        }
        return $executed;
    }

    /**
     * @return string[] filenames already recorded as applied
     */
    public function applied(): array
    {
        try {
            $rows = $this->database->fetchAll("SELECT filename FROM `" . self::TABLE . "` ORDER BY filename ASC");
        } catch (\PDOException | QueryException $exception) {
            if ($this->isMissingMigrationsTable($exception)) {
                return [];
            }
            throw $exception;
        }
        return array_column($rows ?: [], 'filename');
    }

    private function isMissingMigrationsTable(\Throwable $exception): bool
    {
        $message = $exception->getMessage();
        $code    = (string)$exception->getCode();

        return strpos($code, '42S02') !== false
            || strpos($message, '1146') !== false
            || stripos($message, 'no such table') !== false;
    }

    /**
     * @return string[] filenames discovered on disk, sorted lexicographically
     */
    public function available(): array
    {
        return self::discoverMigrations($this->directory);
    }

    /**
     * Pure file-discovery helper, exposed for testing.
     *
     * @return string[] sorted basenames of `*.sql` files in $directory
     */
    public static function discoverMigrations(string $directory): array
    {
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names);
        return $names;
    }

    private function apply(string $filename): void
    {
        $path = $this->directory . '/' . $filename;
        $sql  = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new DatabaseException("Migration '$filename' is empty or unreadable", 500);
        }

        $connection = $this->database->connect();
        try {
            $connection->exec($sql);
        } catch (\PDOException $exception) {
            throw new DatabaseException(
                "Migration '$filename' failed: " . $exception->getMessage(),
                500,
                $exception
            );
        }

        $this->database->query(
            "INSERT INTO `" . self::TABLE . "` (filename) VALUES (:filename)",
            ['filename' => $filename]
        );
    }

    private function ensureTable(): void
    {
        $connection = $this->database->connect();
        $connection->exec(
            "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                filename   VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
