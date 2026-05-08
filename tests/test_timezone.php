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

echo "\nRunning timezone tests:\n";

test('PHP default timezone is UTC', function () {
    assert_equals('UTC', date_default_timezone_get(), 'bootstrap should set UTC');
});

test('PHP and SQLite agree on current time (within 2s)', function () {
    $php_now = strtotime(gmdate('Y-m-d H:i:s'));
    $sqlite_now = strtotime(db()->query("SELECT datetime('now')")->fetchColumn());
    $diff = abs($php_now - $sqlite_now);
    assert_true($diff <= 2, "PHP and SQLite differ by {$diff}s (expected <= 2)");
});

test('inserted row has UTC created_at matching PHP gmdate', function () {
    $stmt = db()->prepare("INSERT INTO documents (title, body, created_by) VALUES ('tz test', 'x', 1)");
    $stmt->execute();
    $id = (int) db()->lastInsertId();
    $row = db()->query("SELECT created_at FROM documents WHERE id = {$id}")->fetch();
    $stored = strtotime($row['created_at']);
    $now = strtotime(gmdate('Y-m-d H:i:s'));
    $diff = abs($now - $stored);
    assert_true($diff <= 2, "stored created_at differs from PHP UTC now by {$diff}s");
    db()->exec("DELETE FROM documents WHERE id = {$id}");
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
