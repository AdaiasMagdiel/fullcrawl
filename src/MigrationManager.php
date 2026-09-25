<?php

namespace AdaiasMagdiel\FullCrawl;

use PDO;
use Throwable;
use RuntimeException;

class MigrationManager
{
    private string $table = 'migrations_history';

    public function __construct(
        private PDO $pdo,
        private string $migrationsDir
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (!is_dir($this->migrationsDir)) {
            mkdir($this->migrationsDir, 0755, true);
        }
        $this->ensureHistoryTable();
    }

    private function ensureHistoryTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = DatabaseQueries::getCreateTableSql($driver, $this->table);
        $this->pdo->exec($sql);
    }

    public function create(string $name): string
    {
        $timestamp = date('Ymd_His');
        $name = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $name = (string) preg_replace('/[^a-zA-Z0-9\s]/', '', $name);

        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/\s+/', '_', $slug);

        $filename = "{$timestamp}_{$slug}.php";
        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;

        $content = Stubs::getMigrationTemplate($name);
        file_put_contents($path, $content);

        return $filename;
    }

    public function run(): bool
    {
        // wipe() may have dropped migrations_history; recreate it if needed
        // so --fresh (wipe + run) keeps working.
        $this->ensureHistoryTable();

        $stmt = $this->pdo->query("SELECT migration FROM {$this->table}");
        if (!$stmt) throw new RuntimeException("Failed to fetch migration history.");

        $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false) $files = [];
        sort($files);

        $batch = $this->getNextBatch();
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $executed)) continue;

            $migration = require $file;

            /**
             * MySQL Edge Case: DDL (CREATE/DROP) causes an implicit commit, so
             * inTransaction() may already be false by the time we reach commit/rollback.
             * We still always start a transaction so pure-DML migrations stay atomic.
             */
            try {
                $this->pdo->beginTransaction();

                // 1. Execute the migration
                $migration['up']($this->pdo);

                // 2. Record in history
                $stmtInsert = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)");
                $stmtInsert->execute([$name, $batch]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                echo "✔ Applied: $name\n";
                $count++;
            } catch (Throwable $e) {
                $rolledBack = false;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                    $rolledBack = true;
                }
                $this->printError("Error in $name", $e->getMessage(), $rolledBack);
                return false;
            }
        }

        echo $count > 0 ? "\nSummary: $count migration(s) applied (Batch $batch).\n" : "No pending migrations.\n";

        return true;
    }

    public function rollback(): bool
    {
        $this->ensureHistoryTable();

        $batchStmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        /** @var int|false|null $batch */
        $batch = $batchStmt ? $batchStmt->fetchColumn() : null;

        if ($batchStmt) {
            $batchStmt->closeCursor();
        }

        if (!$batch) {
            echo "Nothing to rollback.\n";
            return true;
        }

        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([(int) $batch]);
        /** @var array<string> $migrations */
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor(); // Libera o lock de leitura imediatamente

        if (empty($migrations)) {
            return true;
        }

        echo "⏮ Rolling back batch $batch...\n";

        foreach ($migrations as $name) {
            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $name;

            $this->pdo->beginTransaction();

            try {
                if (file_exists($path)) {
                    $migration = require $path;
                    $migration['down']($this->pdo);
                }

                $stmtDel = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
                $stmtDel->execute([$name]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                echo "↩ Reverted: $name\n";
            } catch (Throwable $e) {
                $rolledBack = false;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                    $rolledBack = true;
                }
                $this->printError("Error reverting $name", $e->getMessage(), $rolledBack);
                return false;
            }
        }

        return true;
    }

    public function redo(string $name): bool
    {
        $this->ensureHistoryTable();

        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $name;
        if (!file_exists($path)) {
            echo "❌ Migration file not found: $name\n";
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT batch FROM {$this->table} WHERE migration = ?");
        $stmt->execute([$name]);
        $batch = $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($batch === false) {
            echo "❌ Migration not applied yet: $name\n";
            return false;
        }

        $migration = require $path;

        try {
            $this->pdo->beginTransaction();

            $migration['down']($this->pdo);

            $stmtDel = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
            $stmtDel->execute([$name]);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            echo "↩ Reverted: $name\n";
        } catch (Throwable $e) {
            $rolledBack = false;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $rolledBack = true;
            }
            $this->printError("Error reverting $name", $e->getMessage(), $rolledBack);
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $migration['up']($this->pdo);

            $stmtInsert = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)");
            $stmtInsert->execute([$name, (int) $batch]);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            echo "✔ Applied: $name\n";
        } catch (Throwable $e) {
            $rolledBack = false;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                $rolledBack = true;
            }
            $this->printError("Error in $name", $e->getMessage(), $rolledBack);
            return false;
        }

        return true;
    }

    public function status(): void
    {
        $this->ensureHistoryTable();

        $stmt = $this->pdo->query("SELECT migration, batch, executed_at FROM {$this->table} ORDER BY id ASC");
        if (!$stmt) throw new RuntimeException("Failed to fetch status.");

        /** @var array<array{migration: string, batch: int, executed_at: string}> $executed */
        $executed = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false) $files = [];
        sort($files);

        echo "\nFullCrawl Migration Status\n" . str_repeat('=', 50) . "\n";
        echo sprintf("%-40s | %-10s\n", "Migration Name", "Status");
        echo str_repeat('-', 50) . "\n";

        foreach ($files as $file) {
            $name = basename($file);
            $info = null;

            foreach ($executed as $row) {
                if ($row['migration'] === $name) {
                    $info = $row;
                    break;
                }
            }

            $status = $info ? "Applied (Batch {$info['batch']})" : "Pending";
            echo sprintf("%-40s | %-10s\n", $name, $status);
        }
    }

    public function wipe(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = [];

        if ($driver === 'mysql') {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $stmt = $this->pdo->query("SHOW TABLES");
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } elseif ($driver === 'sqlite') {
            $this->pdo->exec("PRAGMA foreign_keys = OFF");
            $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } else {
            $stmt = $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        }

        foreach ($tables as $table) {
            $tableName = (string) $table;
            $quote = ($driver === 'mysql') ? "`" : '"';
            $this->pdo->exec("DROP TABLE {$quote}{$tableName}{$quote}");
            echo "🗑️ Dropped: {$tableName}\n";
        }

        if ($driver === 'mysql') {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } elseif ($driver === 'sqlite') {
            $this->pdo->exec("PRAGMA foreign_keys = ON");
        }
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        if (!$stmt) return 1;

        $max = $stmt->fetchColumn();
        $stmt->closeCursor();

        return ((int) $max) + 1;
    }

    private function printError(string $title, string $message, bool $rolledBack = true): void
    {
        $status = $rolledBack ? "Transaction rolled back.\n" : "No transaction was active; already-executed statements may have been committed (e.g. MySQL DDL). Manual cleanup may be required.\n";
        echo "\n" . str_repeat('-', 30) . "\n❌ {$title}\nReason: {$message}\n{$status}" . str_repeat('-', 30) . "\n";
    }
}
