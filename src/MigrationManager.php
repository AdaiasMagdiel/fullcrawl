<?php

namespace AdaiasMagdiel\FullCrawl;

use PDO;
use Throwable;

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
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = DatabaseQueries::getCreateTableSql($driver, $this->table);
        $this->pdo->exec($sql);
    }

    public function create(string $name): string
    {
        $timestamp = date('Ymd_His');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '_', trim($name)));
        $filename = "{$timestamp}_{$slug}.php";

        $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $filename;
        $content = Stubs::getMigrationTemplate($name);

        file_put_contents($path, $content);
        return $filename;
    }

    public function run(): void
    {
        $executed = $this->pdo->query("SELECT migration FROM {$this->table}")->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->migrationsDir . '/*.php');
        sort($files);

        $batch = $this->getNextBatch();
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $executed)) continue;

            $migration = require $file;

            $this->pdo->beginTransaction();
            try {
                $migration['up']($this->pdo);

                $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)");
                $stmt->execute([$name, $batch]);

                $this->pdo->commit();
                echo "✔ Applied: $name\n";
                $count++;
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                $this->printError("Error in $name", $e->getMessage());
                return;
            }
        }

        echo $count > 0 ? "\nSummary: $count migration(s) applied (Batch $batch).\n" : "No pending migrations.\n";
    }

    public function rollback(): void
    {
        $batch = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}")->fetchColumn();
        if (!$batch) {
            echo "Nothing to rollback.\n";
            return;
        }

        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $migrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

        echo "⏮ Rolling back batch $batch...\n";

        foreach ($migrations as $name) {
            $path = $this->migrationsDir . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($path)) {
                echo "⚠️ Warning: File $name not found. Skipping.\n";
                continue;
            }

            $migration = require $path;

            $this->pdo->beginTransaction();
            try {
                $migration['down']($this->pdo);

                $stmtDel = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
                $stmtDel->execute([$name]);

                $this->pdo->commit();
                echo "↩ Reverted: $name\n";
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                $this->printError("Error reverting $name", $e->getMessage());
                return;
            }
        }
    }

    public function status(): void
    {
        $executed = $this->pdo->query("SELECT migration, batch, executed_at FROM {$this->table} ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);

        $executedNames = array_column($executed, 'migration');
        $files = glob($this->migrationsDir . '/*.php');
        sort($files);

        echo "\nFullCrawl Migration Status\n";
        echo str_repeat('=', 50) . "\n";
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
        echo str_repeat('=', 50) . "\n";
    }

    public function fresh(): void
    {
        echo "⚠️  Dropping all tables and re-running all migrations...\n";
        $this->wipe();
        $this->ensureHistoryTable(); // Recria a tabela de controle
        $this->run();
    }

    public function wipe(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($tables)) return;

        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); // Apenas MySQL, ignorado por outros
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE `{$table}`");
            echo "🗑️  Dropped: {$table}\n";
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    private function getNextBatch(): int
    {
        return (int)$this->pdo->query("SELECT MAX(batch) FROM {$this->table}")->fetchColumn() + 1;
    }

    private function printError(string $title, string $message): void
    {
        echo "\n" . str_repeat('-', 30) . "\n";
        echo "❌ {$title}\n";
        echo "Reason: {$message}\n";
        echo "Transaction rolled back. Execution stopped.\n";
        echo str_repeat('-', 30) . "\n";
    }
}
