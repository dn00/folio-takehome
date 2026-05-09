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

function search_docs(string $query): array {
    if ($query === '') {
        return db()->query('
            SELECT d.*, s.name AS creator_name
            FROM documents d
            JOIN staff s ON s.id = d.created_by
            ORDER BY d.created_at DESC
        ')->fetchAll();
    }
    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $query);
    $stmt = db()->prepare("
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ? ESCAPE '\\'
        ORDER BY d.created_at DESC
    ");
    $stmt->execute(['%' . $escaped . '%']);
    return $stmt->fetchAll();
}

echo "\nRunning search tests:\n";

test('search by exact title finds document', function () {
    $results = search_docs('Welcome Packet');
    assert_true(count($results) >= 1, 'should find at least one result');
    assert_equals('Welcome Packet', $results[0]['title']);
});

test('search by partial title (middle) finds document', function () {
    $results = search_docs('come Pack');
    assert_true(count($results) >= 1, 'partial match should find result');
    assert_equals('Welcome Packet', $results[0]['title']);
});

test('search with no results returns empty', function () {
    $results = search_docs('xyznonexistent999');
    assert_equals(0, count($results), 'should return no results');
});

test('search is case-insensitive', function () {
    $results = search_docs('welcome packet');
    assert_true(count($results) >= 1, 'lowercase search should find result');
    assert_equals('Welcome Packet', $results[0]['title']);
});

test('empty query returns all documents', function () {
    $all = db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    $results = search_docs('');
    assert_equals((int) $all, count($results), 'empty search should return all docs');
});

test('wildcard characters are treated as literals', function () {
    $results = search_docs('%');
    assert_equals(0, count($results), 'percent sign should not match everything');
});

test('SQL injection attempt is harmless', function () {
    $results = search_docs("'; DROP TABLE documents; --");
    assert_equals(0, count($results), 'SQL injection should return no results, not crash');
    $count = db()->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    assert_true((int) $count > 0, 'documents table should still exist');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
