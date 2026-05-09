<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at_raw = trim($_POST['publish_at'] ?? '');

    $publish_at = parse_publish_at($publish_at_raw);
    if ($publish_at === false) {
        $error = 'Invalid publish date. Use the date picker or format: YYYY-MM-DDTHH:MM';
        $publish_at = null;
    }

    if (!$error && ($title === '' || $body === '')) {
        $error = 'Title and body are required.';
    }

    if (!$error) {
        $readable_id = generate_readable_id($title);
        $attempts = 0;
        while (true) {
            try {
                $stmt = db()->prepare('
                    INSERT INTO documents (title, body, created_by, publish_at, readable_id)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$title, $body, $staff['id'], $publish_at, $readable_id]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && ++$attempts < 3) {
                    $readable_id = generate_readable_id($title);
                    continue;
                }
                throw $e;
            }
        }
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, ['title' => $title, 'publish_at' => $publish_at, 'readable_id' => $readable_id]);

        header('Location: /admin.php?created=' . urlencode($readable_id));
        exit;
    }
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = db()->prepare("
        SELECT d.id, d.title, d.created_at, d.publish_at, d.readable_id, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ? ESCAPE '\\'
        ORDER BY d.created_at DESC
    ");
    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
    $stmt->execute(['%' . $escaped . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.id, d.title, d.created_at, d.publish_at, d.readable_id, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document <?= h($_GET['created']) ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (UTC, optional)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <div class="search-input-wrap">
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search by title...">
            <?php if ($search !== ''): ?>
                <a href="/admin.php" class="search-clear" title="Clear search">&times;</a>
            <?php endif ?>
        </div>
        <button type="submit" class="btn">Search</button>
    </form>
    <?php if ($search !== '' && empty($docs)): ?>
        <p class="empty">No documents found for "<?= h($search) ?>".</p>
    <?php elseif ($search !== ''): ?>
        <p class="meta">Showing <?= count($docs) ?> result(s) for "<?= h($search) ?>"</p>
    <?php endif ?>
    <?php if (empty($docs) && $search === ''): ?>
        <p class="empty">No documents yet.</p>
    <?php elseif (!empty($docs)): ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['readable_id'] ?? '#' . $d['id']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td><?php
                            if ($d['publish_at'] === null || $d['publish_at'] <= gmdate('Y-m-d H:i:s')) {
                                echo 'Published';
                            } else {
                                echo 'Scheduled: ' . h($d['publish_at']) . ' UTC';
                            }
                        ?></td>
                        <td><a href="/share.php?doc=<?= h($d['readable_id'] ?? $d['id']) ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
