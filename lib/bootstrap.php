<?php

date_default_timezone_set('UTC');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $path = __DIR__ . '/../db.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

function current_staff(): array {
    $stmt = db()->prepare('SELECT * FROM staff WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('No staff row #1 found. Did you run `php seed.php`?');
    }
    return $row;
}

function audit_log(string $action, string $entity_type, int $entity_id, array $details = []): void {
    $staff = current_staff();
    $stmt = db()->prepare('
        INSERT INTO audit_log (staff_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $staff['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($details),
    ]);
}

function random_token(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function generate_readable_id(string $title): string
{
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if (strlen($slug) > 40) {
        $slug = substr($slug, 0, 40);
        $slug = rtrim($slug, '-');
    }
    if ($slug === '') {
        $slug = 'doc';
    }
    $charset = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $suffix = '';
    $bytes = random_bytes(4);
    for ($i = 0; $i < 4; $i++) {
        $suffix .= $charset[ord($bytes[$i]) % 36];
    }
    return $slug . '-' . $suffix;
}

function parse_publish_at(string $raw)
{
    if ($raw === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw, new DateTimeZone('UTC'));
    if (!$dt) {
        return false;
    }
    $errors = DateTime::getLastErrors();
    if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return false;
    }
    if ($dt->format('Y-m-d\TH:i') !== $raw) {
        return false;
    }
    return $dt->format('Y-m-d H:i:s');
}
