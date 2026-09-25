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
| `--redo "file"` | Reverts and re-runs a single migration, picking up edits made to it after it was applied. |
| `--status` | Displays a detailed list of applied and pending migrations, flagging any applied file whose content has changed since it ran. |
| `--fresh` | **Destructive**: Drops all tables and re-runs all migrations. |
| `--wipe` | **Destructive**: Drops all tables without re-running migrations. |

### Anatomy of a Migration

When you run `fullcrawl --new`, a file is created in `database/migrations/`. You have full access to the `$pdo` object. Prefer idempotent statements (`IF NOT EXISTS` / `IF EXISTS`), so a migration can be safely re-run after a failure:

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

## What the `fullcrawl` Script Actually Does

[`bin/fullcrawl`](bin/fullcrawl) is a plain PHP script installed by Composer as an executable — there's no compiled binary or hidden network call. Here's exactly what it does, in order, every time you run it:

1. **Finds Composer's autoloader.** It checks `vendor/autoload.php` in your current directory first, falling back to the package's own `vendor/autoload.php` if you're running it from somewhere else. If neither exists, it exits with a clear error instead of failing later with an unrelated "Class not found".
2. **Loads `fullcrawl.php` from your project root.** This is your own file — the script just does `require`. If it's missing, it exits with an error before touching anything else.
3. **Validates the return value is a `PDO` instance.** If `fullcrawl.php` returns anything else, it exits immediately. This is the only "trust" boundary: the script never opens a database connection itself, it only ever uses the one *you* constructed and handed it.
4. **Instantiates `MigrationManager`** with that `$pdo` and `<cwd>/database/migrations`, which on construction runs one `CREATE TABLE IF NOT EXISTS` for its own history table — no other schema changes happen yet.
5. **Dispatches on `$argv[1]`** (`--new`, `--run`, `--rollback`, `--redo`, `--status`, `--fresh`, `--wipe`) to the matching `MigrationManager` method. `--fresh` and `--wipe` are the only ones that touch existing data, and `--fresh` prompts for a `y/n` confirmation on stdin before doing anything.
6. **Runs your migration files**, which are also just PHP files under `database/migrations/` that you wrote — the script `require`s each one and calls its `up`/`down` closure with the same `$pdo`.

In short: the script never reaches out to the network, never opens a connection on its own, and every SQL statement that runs comes from either its own fixed history-table DDL or a migration file that lives in your repo and that you can read before running.

---

## License

FullCrawl is licensed under the **GPLv3**. I believe in free and open software. See the [LICENSE](LICENSE) and the [COPYRIGHT](COPYRIGHT) files for details.
