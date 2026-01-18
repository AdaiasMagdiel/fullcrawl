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

    public function run(): void
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table}");
        if (!$stmt) throw new RuntimeException("Failed to fetch migration history.");

        $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->migrationsDir . '/*.php');
        if ($files === false) $files = [];
        sort($files);

        $batch = $this->getNextBatch();
        $count = 0;
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $executed)) continue;

            $migration = require $file;

            /**
             * MySQL Edge Case: DDL (CREATE/DROP) causes an implicit commit.
             * We only start transactions for drivers that support DDL Transactions (SQLite/Postgres).
             */
            $supportsTransactionalDDL = ($driver !== 'mysql');

            try {
                if ($supportsTransactionalDDL) {
                    $this->pdo->beginTransaction();
                }

                // 1. Execute the migration
                $migration['up']($this->pdo);

                // 2. Record in history
                $stmtInsert = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)");
                $stmtInsert->execute([$name, $batch]);

                if ($supportsTransactionalDDL && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                echo "✔ Applied: $name\n";
                $count++;
            } catch (Throwable $e) {
                if ($supportsTransactionalDDL && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->printError("Error in $name", $e->getMessage());
                return;
            }
        }

        echo $count > 0 ? "\nSummary: $count migration(s) applied (Batch $batch).\n" : "No pending migrations.\n";
    }

    public function rollback(): void
    {
        $batchStmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        /** @var int|false|null $batch */
        $batch = $batchStmt ? $batchStmt->fetchColumn() : null;

        if ($batchStmt) {
            $batchStmt->closeCursor();
        }

        if (!$batch) {
            echo "Nothing to rollback.\n";
            return;
        }

        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([(int) $batch]);
        /** @var array<string> $migrations */
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor(); // Libera o lock de leitura imediatamente

        if (empty($migrations)) {
            return;
        }

        echo "⏮ Rolling back batch $batch...\n";

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $supportsTransactionalDDL = ($driver !== 'mysql');

        foreach ($migrations as $name) {
            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $name;

            if ($supportsTransactionalDDL) {
                $this->pdo->beginTransaction();
            }

            try {
                if (file_exists($path)) {
                    $migration = require $path;
                    $migration['down']($this->pdo);
                }

                $stmtDel = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
                $stmtDel->execute([$name]);

                if ($supportsTransactionalDDL && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                echo "↩ Reverted: $name\n";
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->printError("Error reverting $name", $e->getMessage());
                return;
            }
        }
    }

    public function status(): void
    {
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

    private function printError(string $title, string $message): void
    {
        echo "\n" . str_repeat('-', 30) . "\n❌ {$title}\nReason: {$message}\nTransaction rolled back.\n" . str_repeat('-', 30) . "\n";
    }
}
