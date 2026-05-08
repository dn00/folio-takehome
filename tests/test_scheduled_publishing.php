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

$test_doc_ids = [];
$test_share_tokens = [];

function create_doc_with_schedule(?string $publish_at): int {
    global $test_doc_ids;
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Test Doc', 'Test body', $publish_at]);
    $id = (int) db()->lastInsertId();
    $test_doc_ids[] = $id;
    return $id;
}

function create_share_for_doc(int $docId): string {
    global $test_share_tokens;
    $token = bin2hex(random_bytes(16));
    $stmt = db()->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)');
    $stmt->execute([$docId, $token, 'test@example.com']);
    $test_share_tokens[] = $token;
    return $token;
}

function cleanup(): void {
    global $test_doc_ids, $test_share_tokens;
    foreach ($test_share_tokens as $token) {
        db()->exec("DELETE FROM shares WHERE token = " . db()->quote($token));
    }
    foreach ($test_doc_ids as $id) {
        db()->exec("DELETE FROM audit_log WHERE entity_type = 'document' AND entity_id = {$id}");
        db()->exec("DELETE FROM documents WHERE id = {$id}");
    }
    $test_doc_ids = [];
    $test_share_tokens = [];
}

function fetch_doc_via_token(string $token): ?array {
    $stmt = db()->prepare('
        SELECT d.*, s.recipient_email
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function doc_is_visible(array $doc): bool {
    return $doc['publish_at'] === null || $doc['publish_at'] <= gmdate('Y-m-d H:i:s');
}

echo "\nRunning scheduled publishing tests:\n";

// --- Happy Path ---

test('future publish_at: doc not visible', function () {
    $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));
    $docId = create_doc_with_schedule($future);
    $token = create_share_for_doc($docId);
    $doc = fetch_doc_via_token($token);

    assert_true($doc !== null, 'token should resolve');
    assert_true(!doc_is_visible($doc), 'doc should not be visible before publish_at');
});

test('past publish_at: doc visible', function () {
    $past = gmdate('Y-m-d H:i:s', strtotime('-1 hour'));
    $docId = create_doc_with_schedule($past);
    $token = create_share_for_doc($docId);
    $doc = fetch_doc_via_token($token);

    assert_true($doc !== null, 'token should resolve');
    assert_true(doc_is_visible($doc), 'doc should be visible after publish_at');
});

test('NULL publish_at: doc visible (backwards compat)', function () {
    $docId = create_doc_with_schedule(null);
    $token = create_share_for_doc($docId);
    $doc = fetch_doc_via_token($token);

    assert_true($doc !== null, 'token should resolve');
    assert_true(doc_is_visible($doc), 'doc with null publish_at should be visible');
});

test('audit_log includes publish_at on creation', function () {
    $future = gmdate('Y-m-d H:i:s', strtotime('+2 hours'));
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at) VALUES (?, ?, 1, ?)');
    $stmt->execute(['Audit Test', 'body', $future]);
    $docId = (int) db()->lastInsertId();

    audit_log('create', 'document', $docId, ['title' => 'Audit Test', 'publish_at' => $future]);

    $log = db()->prepare('SELECT details FROM audit_log WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1');
    $log->execute(['document', $docId]);
    $details = json_decode($log->fetchColumn(), true);

    assert_equals($future, $details['publish_at'], 'audit log should contain publish_at');
});

// --- Sad Path ---

test('invalid datetime treated as no schedule', function () {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', 'not-a-date', new DateTimeZone('UTC'));
    assert_true($dt === false, 'invalid datetime should fail to parse');
});

test('token for scheduled doc resolves (not null)', function () {
    $future = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));
    $docId = create_doc_with_schedule($future);
    $token = create_share_for_doc($docId);
    $doc = fetch_doc_via_token($token);

    assert_true($doc !== null, 'scheduled doc should still be fetchable by token');
});

// --- Edge Cases ---

test('publish_at = current second: doc is visible', function () {
    $now = gmdate('Y-m-d H:i:s');
    $docId = create_doc_with_schedule($now);
    $token = create_share_for_doc($docId);
    $doc = fetch_doc_via_token($token);

    assert_true(doc_is_visible($doc), 'doc should be visible when publish_at equals now');
});

// --- Form Parsing Tests (exercise lib/bootstrap.php parse_publish_at) ---

test('valid datetime-local input parses to correct DB format', function () {
    $result = parse_publish_at('2026-06-15T14:30');
    assert_equals('2026-06-15 14:30:00', $result, 'should convert datetime-local to DB format');
});

test('empty string input returns null (no schedule)', function () {
    $result = parse_publish_at('');
    assert_true($result === null, 'empty string should return null');
});

test('invalid date input returns false (rejected)', function () {
    $result = parse_publish_at('not-a-date');
    assert_true($result === false, 'garbage input should return false');
});

test('overflow date (Feb 30) is rejected', function () {
    $result = parse_publish_at('2026-02-30T14:00');
    assert_true($result === false, 'Feb 30 should be rejected as invalid');
});

cleanup();

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
