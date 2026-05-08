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

echo "\nRunning readable ID tests:\n";

// --- Core Tests ---

test('generated ID has expected format (slug + 4 alphanum)', function () {
    $id = generate_readable_id('Hello World');
    assert_true((bool) preg_match('/^hello-world-[a-z0-9]{4}$/', $id), "unexpected format: {$id}");
});

test('two docs with identical title get different readable_ids', function () {
    $id1 = generate_readable_id('Duplicate Title');
    $id2 = generate_readable_id('Duplicate Title');
    assert_true($id1 !== $id2, 'IDs should differ');
});

test('share.php resolves doc by readable_id', function () {
    $row = db()->query("SELECT readable_id FROM documents LIMIT 1")->fetch();
    assert_true($row !== false, 'should have a seeded doc');
    $stmt = db()->prepare('SELECT * FROM documents WHERE readable_id = ?');
    $stmt->execute([$row['readable_id']]);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'should resolve by readable_id');
    assert_equals('Welcome Packet', $doc['title']);
});

// --- Time-permitting ---

test('title with only special characters uses fallback', function () {
    $id = generate_readable_id('!!!@@@###');
    assert_true((bool) preg_match('/^doc-[a-z0-9]{4}$/', $id), "expected fallback format, got: {$id}");
});

test('very long title is truncated', function () {
    $longTitle = str_repeat('word ', 50);
    $id = generate_readable_id($longTitle);
    $parts = explode('-', $id);
    array_pop($parts);
    $slug = implode('-', $parts);
    assert_true(strlen($slug) <= 40, "slug too long: " . strlen($slug));
});

test('seeded doc has readable_id set', function () {
    $doc = db()->query("SELECT readable_id FROM documents WHERE title = 'Welcome Packet'")->fetch();
    assert_true($doc !== false && $doc['readable_id'] !== null, 'seeded doc should have readable_id');
    assert_true((bool) preg_match('/^welcome-packet-[a-z0-9]{4}$/', $doc['readable_id']), 'unexpected format: ' . $doc['readable_id']);
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
