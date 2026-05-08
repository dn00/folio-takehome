<?php

require_once __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ': ' : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function create_migration(string $filename, string $sql): string {
    $path = __DIR__ . '/../migrations/' . $filename;
    file_put_contents($path, $sql);
    return $path;
}

function cleanup_migration(string $filename): void {
    $path = __DIR__ . '/../migrations/' . $filename;
    if (file_exists($path)) {
        unlink($path);
    }
}

function run_migrate(): string {
    $output = '';
    exec('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' 2>&1', $lines, $rc);
    return implode("\n", $lines);
}

function migration_recorded(string $filename): bool {
    $stmt = db()->prepare('SELECT COUNT(*) FROM migrations WHERE filename = ?');
    $stmt->execute([$filename]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "\nRunning migration tests:\n";

// --- Happy Path ---

test('valid migration applies and is recorded', function () {
    $file = '900_test_happy.sql';
    cleanup_migration($file);
    create_migration($file, 'CREATE TABLE _test_happy (id INTEGER PRIMARY KEY);');

    run_migrate();

    assert_true(migration_recorded($file), 'migration should be recorded');

    $tables = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_test_happy'")->fetchColumn();
    assert_equals('_test_happy', $tables, 'table should exist');

    db()->exec('DROP TABLE IF EXISTS _test_happy');
    db()->exec("DELETE FROM migrations WHERE filename = '{$file}'");
    cleanup_migration($file);
});

test('multi-statement migration applies all statements', function () {
    $file = '901_test_multi.sql';
    cleanup_migration($file);
    create_migration($file, "CREATE TABLE _test_multi_a (id INTEGER PRIMARY KEY);\nCREATE TABLE _test_multi_b (id INTEGER PRIMARY KEY);");

    run_migrate();

    assert_true(migration_recorded($file), 'migration should be recorded');

    $a = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_test_multi_a'")->fetchColumn();
    $b = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_test_multi_b'")->fetchColumn();
    assert_equals('_test_multi_a', $a, 'table A should exist');
    assert_equals('_test_multi_b', $b, 'table B should exist');

    db()->exec('DROP TABLE IF EXISTS _test_multi_a');
    db()->exec('DROP TABLE IF EXISTS _test_multi_b');
    db()->exec("DELETE FROM migrations WHERE filename = '{$file}'");
    cleanup_migration($file);
});

// --- Sad Path ---

test('invalid SQL rolls back and is not recorded', function () {
    $file = '902_test_bad_sql.sql';
    cleanup_migration($file);
    create_migration($file, 'CREATE TABLE _test_bad (id INTEGER PRIMARY KEY); INVALID SQL GARBAGE;');

    run_migrate();

    assert_true(!migration_recorded($file), 'failed migration should not be recorded');

    $table = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_test_bad'")->fetchColumn();
    assert_true($table === false, 'table should not exist after rollback');

    cleanup_migration($file);
});

// --- Edge Cases ---

test('already-applied migration is not re-run', function () {
    $file = '903_test_idempotent.sql';
    cleanup_migration($file);
    create_migration($file, 'CREATE TABLE _test_idem (id INTEGER PRIMARY KEY);');

    run_migrate();
    assert_true(migration_recorded($file), 'first run should record it');

    db()->exec('DROP TABLE _test_idem');
    run_migrate();

    $table = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='_test_idem'")->fetchColumn();
    assert_true($table === false, 'table should not be recreated on second run');

    db()->exec("DELETE FROM migrations WHERE filename = '{$file}'");
    cleanup_migration($file);
});

test('empty migration file is skipped', function () {
    $file = '904_test_empty.sql';
    cleanup_migration($file);
    create_migration($file, '   ');

    run_migrate();

    assert_true(!migration_recorded($file), 'empty migration should not be recorded');

    cleanup_migration($file);
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
