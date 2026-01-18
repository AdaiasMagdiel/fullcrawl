<?php

namespace AdaiasMagdiel\FullCrawl;

class DatabaseQueries
{
    public static function getCreateTableSql(string $driver, string $table): string
    {
        $queries = [
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            'mysql' => "CREATE TABLE IF NOT EXISTS {$table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_migration (migration),
                INDEX idx_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        return $queries[$driver] ?? $queries['mysql'];
    }
}
