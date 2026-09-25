<?php

use AdaiasMagdiel\FullCrawl\DatabaseQueries;
use AdaiasMagdiel\FullCrawl\MigrationManager;

/**
 * Test Suite Setup
 * We use SQLite :memory: to ensure tests are fast and 
 * do not leave side effects on the disk.
 */
beforeEach(function () {
    $this->pdo = new PDO('sqlite::memory:');
    $this->migrationsDir = __DIR__ . '/../temp_migrations';

    if (!is_dir($this->migrationsDir)) {
        mkdir($this->migrationsDir, 0755, true);
    }

    $this->manager = new MigrationManager($this->pdo, $this->migrationsDir);
});

/**
 * Cleanup after each test execution
 */
afterEach(function () {
    $files = glob($this->migrationsDir . '/*.php');
    foreach ($files as $file) {
        unlink($file);
    }
    if (is_dir($this->migrationsDir)) {
        rmdir($this->migrationsDir);
    }
});

afterAll(function () {
    $tempProject = __DIR__ . '/temp_project';
    if (is_dir($tempProject)) {
        // Função auxiliar para deletar pasta recursivamente
        $deleteDir = function ($dirPath) use (&$deleteDir) {
            foreach (glob($dirPath . "/*") as $file) {
                is_dir($file) ? $deleteDir($file) : unlink($file);
            }
            rmdir($dirPath);
        };
        $deleteDir($tempProject);
    }
});

### --- CREATION TESTS --- ###

test('it creates a migration file with correct slugification', function () {
    $name = "Creation of Users and Profiles!!";
    $filename = $this->manager->create($name);

    // Asserts name is sanitized: lowercase, no accents, spaces to underscores
    expect($filename)->toMatch('/^\d{8}_\d{6}_creation_of_users_and_profiles\.php$/');
    expect(file_exists($this->migrationsDir . '/' . $filename))->toBeTrue();
});

### --- EXECUTION TESTS (RUN) --- ###

test('it runs migrations and saves to history table', function () {
    // Create a real test migration
    $filename = $this->manager->create('create_users_table');
    $content = "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];";
    file_put_contents($this->migrationsDir . '/' . $filename, $content);

    expect($this->manager->run())->toBeTrue();

    // Check if table was actually created in the database
    $tableExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
    expect($tableExists)->not->toBeFalse();

    // Check if it was recorded in the migrations_history table
    $history = $this->pdo->query("SELECT migration FROM migrations_history WHERE batch = 1")->fetchColumn();
    expect($history)->toBe($filename);
});

### --- INTEGRITY TESTS (TRANSACTIONS) --- ###

test('it rolls back everything if a migration fails', function () {
    // Create a valid migration
    $this->manager->create('valid_migration');

    // Create a migration that intentionally fails midway
    $failFile = $this->manager->create('failing_migration');
    $content = "<?php return [
        'up' => function(\$pdo) {
            \$pdo->exec('CREATE TABLE secret_data (id INT)');
            throw new Exception('Simulated Failure');
        },
        'down' => fn(\$pdo) => null
    ];";
    file_put_contents($this->migrationsDir . '/' . $failFile, $content);

    // run() must report failure so the CLI can exit non-zero
    expect($this->manager->run())->toBeFalse();

    // 'secret_data' table should NOT exist due to atomicity (rollback)
    $tableExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='secret_data'")->fetch();
    expect($tableExists)->toBeFalse();

    // History table should be empty as the transaction failed
    $count = $this->pdo->query("SELECT COUNT(*) FROM migrations_history")->fetchColumn();
    expect((int)$count)->toBe(0);
});

### --- ROLLBACK AND BATCH TESTS --- ###

test('it rolls back the last batch only', function () {
    // Batch 1 execution
    $file1 = $this->manager->create('m1');
    file_put_contents($this->migrationsDir . '/' . $file1, "<?php return ['up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE t1 (id INT)'), 'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE t1')];");
    $this->manager->run();

    // Batch 2 execution
    $file2 = $this->manager->create('m2');
    file_put_contents($this->migrationsDir . '/' . $file2, "<?php return ['up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE t2 (id INT)'), 'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE t2')];");
    $this->manager->run();

    // Perform Rollback (should only revert Batch 2)
    expect($this->manager->rollback())->toBeTrue();

    expect($this->pdo->query("SELECT name FROM sqlite_master WHERE name='t2'")->fetch())->toBeFalse();
    expect($this->pdo->query("SELECT name FROM sqlite_master WHERE name='t1'")->fetch())->not->toBeFalse();
});

test('it reports failure when a rollback fails', function () {
    $file = $this->manager->create('failing_down');
    $content = "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE t1 (id INT)'),
        'down' => function(\$pdo) { throw new Exception('Simulated Failure'); }
    ];";
    file_put_contents($this->migrationsDir . '/' . $file, $content);
    $this->manager->run();

    // rollback() must report failure so the CLI can exit non-zero
    expect($this->manager->rollback())->toBeFalse();
});

### --- REDO TESTS --- ###

test('it reverts and reapplies a migration, picking up file edits', function () {
    $file = $this->manager->create('users_table');
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");
    $this->manager->run();

    // Simulate editing the migration file after it was applied
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");

    expect($this->manager->redo($file))->toBeTrue();

    $columns = $this->pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    expect($columnNames)->toContain('email');

    // Batch is preserved and the history still has a single row for this migration
    $rows = $this->pdo->query("SELECT batch FROM migrations_history WHERE migration = '$file'")->fetchAll();
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['batch'])->toBe(1);
});

test('it refuses to redo a migration that was never applied', function () {
    $file = $this->manager->create('never_run');
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE t1 (id INT)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE t1')
    ];");

    expect($this->manager->redo($file))->toBeFalse();
});

test('it refuses to redo a missing migration file', function () {
    expect($this->manager->redo('20260101_000000_ghost.php'))->toBeFalse();
});

### --- CLEANUP TESTS (WIPE) --- ###

test('it wipes all tables from database', function () {
    $this->pdo->exec("CREATE TABLE extra_table (id INT)");
    $this->pdo->exec("CREATE TABLE another_one (id INT)");

    $this->manager->wipe();

    // Assert all user tables are gone
    $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
    expect($tables)->toBeEmpty();
});

test('it can run migrations again after a wipe (--fresh)', function () {
    $filename = $this->manager->create('create_users_table');
    $content = "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];";
    file_put_contents($this->migrationsDir . '/' . $filename, $content);

    $this->manager->run();

    // wipe() drops migrations_history itself; run() must self-heal it
    $this->manager->wipe();
    expect(fn() => $this->manager->run())->not->toThrow(Throwable::class);

    $tableExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
    expect($tableExists)->not->toBeFalse();

    $history = $this->pdo->query("SELECT migration FROM migrations_history WHERE batch = 1")->fetchColumn();
    expect($history)->toBe($filename);
});

test('it handles run command with no migration files', function () {
    // Ensuring the directory is empty
    array_map('unlink', glob("$this->migrationsDir/*.*"));

    // This should not throw an exception
    expect(fn() => $this->manager->run())->not->toThrow(Throwable::class);
});

test('it skips rollback if the migration file is missing', function () {
    $filename = $this->manager->create('missing_file');
    $this->manager->run();

    unlink($this->migrationsDir . '/' . $filename);

    // Should skip with a warning instead of a Fatal Error
    expect(fn() => $this->manager->rollback())->not->toThrow(Throwable::class);
});

test('it returns correct SQL for different drivers', function () {
    $sqliteSql = DatabaseQueries::getCreateTableSql('sqlite', 'test_table');
    expect($sqliteSql)->toContain('AUTOINCREMENT');

    $mysqlSql = DatabaseQueries::getCreateTableSql('mysql', 'test_table');
    expect($mysqlSql)->toContain('ENGINE=InnoDB');
});

test('cli command creates a new migration file', function () {
    $tempProject = __DIR__ . '/temp_project';
    mkdir($tempProject . '/database/migrations', 0755, true);

    file_put_contents($tempProject . '/fullcrawl.php', "<?php return new PDO('sqlite::memory:');");

    $binary = realpath(__DIR__ . '/../../bin/fullcrawl');
    $output = shell_exec("cd $tempProject && php $binary --new test_cli");

    expect($output)->toContain('✅ Created:');
    $files = glob($tempProject . '/database/migrations/*.php');
    expect($files)->toHaveCount(1);
});

test('it prints the status of migrations', function () {
    $this->manager->create('m1');
    $this->manager->run();

    ob_start();
    $this->manager->status();
    $output = ob_get_clean();

    expect($output)->toContain('Applied');
});

### --- HASH / DRIFT DETECTION TESTS --- ###

test('it flags a migration as modified when its file changes after being applied', function () {
    $file = $this->manager->create('users_table');
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");
    $this->manager->run();

    $hash = $this->pdo->query("SELECT hash FROM migrations_history WHERE migration = '$file'")->fetchColumn();
    expect($hash)->not->toBeEmpty();

    ob_start();
    $this->manager->status();
    $output = ob_get_clean();
    expect($output)->not->toContain('modified since applied');

    // Edit the file after it was applied
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");

    ob_start();
    $this->manager->status();
    $output = ob_get_clean();
    expect($output)->toContain('modified since applied');
});

test('it clears the modified flag once a migration is redone', function () {
    $file = $this->manager->create('users_table');
    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");
    $this->manager->run();

    file_put_contents($this->migrationsDir . '/' . $file, "<?php return [
        'up' => fn(\$pdo) => \$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)'),
        'down' => fn(\$pdo) => \$pdo->exec('DROP TABLE users')
    ];");

    $this->manager->redo($file);

    ob_start();
    $this->manager->status();
    $output = ob_get_clean();
    expect($output)->not->toContain('modified since applied');
});
