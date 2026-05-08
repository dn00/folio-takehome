<?php

require_once __DIR__ . '/lib/bootstrap.php';

$pdo = db();

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL UNIQUE,
    applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)');

$applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$dir = __DIR__ . '/migrations';
if (!is_dir($dir)) {
    echo "No migrations directory. Nothing to run.\n";
    exit(0);
}

$files = glob($dir . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    $sql = trim(file_get_contents($file));
    if ($sql === '') {
        continue;
    }
    $pdo->beginTransaction();
    try {
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException("Migration {$name} failed: " . $e->getMessage(), 0, $e);
    }
    echo "  Applied: {$name}\n";
    $ran++;
}

if ($ran === 0) {
    echo "  No new migrations.\n";
} else {
    echo "  {$ran} migration(s) applied.\n";
}
