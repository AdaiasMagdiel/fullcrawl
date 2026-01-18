# FullCrawl ⚡

**FullCrawl** is a high-performance, framework-agnostic database migration system for PHP. It follows a "Zero Configuration" philosophy by inheriting your project's existing `PDO` connection, ensuring atomic operations through native database transactions.

## Why FullCrawl?

Most migration systems force you to re-configure database credentials or bind you to a specific framework's ORM. **FullCrawl** breaks this cycle:

* **Zero-Config:** It uses the connection you already established.
* **Atomic Operations:** Every migration batch is wrapped in a transaction. If one fails, they all roll back.
* **Framework Agnostic:** Use it with [Rubik](https://github.com/AdaiasMagdiel/Rubik-ORM), Slim, Lumen, WordPress, or your own custom-built engine.
* **True Injection:** No global states. The `$pdo` instance is injected directly into your migration closures.

---

## Quick Start

### 1. Installation

```bash
composer require AdaiasMagdiel/FullCrawl

```

### 2. The Hook (`fullcrawl.php`)

Create a file named `fullcrawl.php` in your project root. It must return your active `PDO` instance.

```php
<?php
// fullcrawl.php

require_once __DIR__ . '/vendor/autoload.php';

// Return your existing PDO instance from your Service Container, 
// Singleton, or Connection factory.
return \App\Core\Database::getInstance()->getConnection();

```

---

## Usage

### Command Line Interface

FullCrawl provides a powerful CLI to manage your database schema:

| Command | Description |
| --- | --- |
| `--new "name"` | Generates a new migration stub with a timestamped filename. |
| `--run` | Executes all pending migrations within a new batch. |
| `--rollback` | Reverts the last successful batch of migrations. |
| `--status` | Displays a detailed list of applied and pending migrations. |
| `--fresh` | **Destructive**: Drops all tables and re-runs all migrations. |

### Anatomy of a Migration

When you run `fullcrawl --new`, a file is created in `database/migrations/`. You have full access to the `$pdo` object:

```php
<?php

/**
 * FullCrawl Migration: create_users_table
 */
return [
    'up' => function(PDO $pdo) {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB");
    },
    'down' => function(PDO $pdo) {
        $pdo->exec("DROP TABLE users");
    }
];

```

---

## Directory Structure

```text
.
├── database/
│   └── migrations/    # Your migration files (auto-created)
├── fullcrawl.php      # The PDO hook
└── vendor/

```

---

## License

FullCrawl is licensed under the **GPLv3**. I believe in free and open software. See the [LICENSE](LICENSE) and the [COPYRIGHT](COPYRIGHT) files for details.
