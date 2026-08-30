# FullCrawl ⚡

[![Tests](https://github.com/AdaiasMagdiel/fullcrawl/actions/workflows/tests.yml/badge.svg)](https://github.com/AdaiasMagdiel/fullcrawl/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/adaiasmagdiel/fullcrawl.svg)](https://packagist.org/packages/adaiasmagdiel/fullcrawl)
[![Total Downloads](https://img.shields.io/packagist/dt/adaiasmagdiel/fullcrawl.svg)](https://packagist.org/packages/adaiasmagdiel/fullcrawl)
[![License](https://img.shields.io/badge/license-GPLv3-blue.svg)](LICENSE)

**FullCrawl** is a high-performance, framework-agnostic database migration system for PHP. It follows a "Zero Configuration" philosophy by inheriting your project's existing `PDO` connection, ensuring atomic operations through native database transactions.

## Why Use a Migration System?

Without one, schema changes usually end up as loose `.sql` files passed around in chat, or manual `ALTER TABLE` runs typed straight into a production console. That falls apart quickly:

* **No single source of truth.** Nobody can tell, just by looking at the repo, what the database is supposed to look like right now.
* **Environments drift.** Dev, staging, and a teammate's machine each end up with a slightly different schema because someone forgot to run (or share) a script.
* **No safe way back.** A bad manual change has no defined undo; you're restoring from a backup, if you have one.
* **No history.** There's no record of what changed, when, or in what order relative to the rest of the codebase.

A migration system fixes this by turning schema changes into ordered, version-controlled files that live next to the code that depends on them, and by tracking which ones have already run so `--run` is always safe to call, on any machine, at any time.

**You probably don't need one if:** your schema is fixed and never changes after initial setup, you're prototyping something disposable, or something else already owns schema management for you (an ORM with its own migration tooling, a managed backend, infrastructure-as-code applying the schema declaratively). Running two schema-management systems against the same database at once is asking for the exact drift problem migrations exist to prevent.

## Why FullCrawl?

Most migration systems force you to re-configure database credentials or bind you to a specific framework's ORM. **FullCrawl** breaks this cycle:

* **Zero-Config:** It uses the connection you already established.
* **Atomic Operations:** Each migration runs inside its own transaction — if it fails, that migration's changes roll back and the batch stops, leaving previously applied migrations in that run committed.
* **Framework Agnostic:** Use it with [Rubik](https://github.com/AdaiasMagdiel/Rubik-ORM), Slim, Lumen, WordPress, or your own custom-built engine.
* **True Injection:** No global states. The `$pdo` instance is injected directly into your migration closures.

---

## Quick Start

### 1. Installation

```bash
composer require adaiasmagdiel/fullcrawl

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
| `--wipe` | **Destructive**: Drops all tables without re-running migrations. |

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

Prefer idempotent statements, so a migration can be safely re-run after a failure:

```php
<?php

/**
 * FullCrawl Migration: create_users_table
 */
return [
    'up' => function(PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB");
    },
    'down' => function(PDO $pdo) {
        $pdo->exec("DROP TABLE IF EXISTS users");
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

## Continuous Integration

Every push and pull request to `main` runs the test suite via [GitHub Actions](.github/workflows/tests.yml), so a change can't reach `main` without the existing behavior still checked. The badge at the top of this README always reflects the latest run on `main`.

What the workflow does, step by step:

1. **Trigger:** runs on `push` and `pull_request` events targeting the `main` branch.
2. **Matrix:** runs the whole job twice in parallel, once per PHP version in `["8.3", "8.4"]` — `pestphp/pest ^4.3` (a dev dependency, not something FullCrawl itself requires) needs PHP 8.3+, so that's the range CI can actually exercise; `fail-fast: false` means one PHP version failing doesn't cancel the other.
3. **Checkout:** pulls the repository at the triggering commit (`actions/checkout`).
4. **Setup PHP:** installs the matrix's PHP version with the `pdo` and `pdo_sqlite` extensions enabled (`shivammathur/setup-php`) — `pdo_sqlite` is what lets the test suite use an in-memory SQLite database instead of a real server.
5. **Install dependencies:** runs `composer install`, resolving the exact versions pinned in `composer.lock`.
6. **Run tests:** runs `./vendor/bin/pest`, the same command used locally; the job fails if any test fails.

You can run the identical checks locally before pushing:

```bash
composer install
./vendor/bin/pest

```

---

## License

FullCrawl is licensed under the **GPLv3**. I believe in free and open software. See the [LICENSE](LICENSE) and the [COPYRIGHT](COPYRIGHT) files for details.
