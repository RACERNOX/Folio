<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

$TIMEZONES = [
    'America/New_York'    => 'Eastern (ET)',
    'America/Chicago'     => 'Central (CT)',
    'America/Denver'      => 'Mountain (MT)',
    'America/Los_Angeles' => 'Pacific (PT)',
    'America/Anchorage'   => 'Alaska (AKT)',
    'Pacific/Honolulu'    => 'Hawaii (HT)',
    'UTC'                 => 'UTC',
];

function sanitize_tz(string $tz): string {
    global $TIMEZONES;
    return isset($TIMEZONES[$tz]) ? $tz : 'America/Chicago';
}

function local_to_utc(string $local, string $tz): ?string {
    try {
        $dt = new DateTime($local, new DateTimeZone($tz));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

function utc_to_tz_input(?string $utc, string $tz = 'America/Chicago'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'update_schedule') {
        $docId = (int) ($_POST['doc_id'] ?? 0);
        if ($docId <= 0) {
            header('Location: /admin.php');
            exit;
        }
        $stmt = db()->prepare('SELECT id FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        if (!$stmt->fetch()) {
            header('Location: /admin.php');
            exit;
        }
        $tz = sanitize_tz($_POST['tz'] ?? '');
        $publish_at = !empty($_POST['publish_at']) ? local_to_utc($_POST['publish_at'], $tz) : null;
        if (!empty($_POST['publish_at']) && $publish_at === null) {
            header('Location: /admin.php?schedule_error=' . $docId);
            exit;
        }
        db()->prepare('UPDATE documents SET publish_at = ?, publish_tz = ? WHERE id = ?')
            ->execute([$publish_at, $tz, $docId]);
        audit_log('update_schedule', 'document', $docId, ['publish_at' => $publish_at, 'timezone' => $tz]);
        header('Location: /admin.php?scheduled=' . $docId);
        exit;
    }

    if ($action === 'delete') {
        $docId = (int) ($_POST['doc_id'] ?? 0);
        if ($docId <= 0) {
            header('Location: /admin.php');
            exit;
        }
        $stmt = db()->prepare('SELECT id, title FROM documents WHERE id = ?');
        $stmt->execute([$docId]);
        $target = $stmt->fetch();
        if ($target) {
            audit_log('delete', 'document', $docId, ['title' => $target['title']]);
            db()->beginTransaction();
            db()->prepare('DELETE FROM shares WHERE document_id = ?')->execute([$docId]);
            db()->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);
            db()->commit();
        }
        header('Location: /admin.php?deleted=' . $docId);
        exit;
    }

    $title      = trim($_POST['title'] ?? '');
    $body       = trim($_POST['body'] ?? '');
    $tz         = sanitize_tz($_POST['tz'] ?? '');
    $publish_at = !empty($_POST['publish_at']) ? local_to_utc($_POST['publish_at'], $tz) : null;

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } elseif (!empty($_POST['publish_at']) && $publish_at === null) {
        $error = 'Invalid publish date/time format.';
    } else {
        try {
            $slug = unique_slug($title);
            $stmt = db()->prepare('
                INSERT INTO documents (title, body, created_by, publish_at, publish_tz, slug)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$title, $body, $staff['id'], $publish_at, $tz, $slug]);
            $docId = (int) db()->lastInsertId();

            audit_log('create', 'document', $docId, [
                'title'      => $title,
                'slug'       => $slug,
                'publish_at' => $publish_at,
                'timezone'   => $tz,
            ]);

            header('Location: /admin.php?created=' . $docId);
            exit;
        } catch (Throwable $e) {
            $error = 'Could not create document. Please try again.';
        }
    }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute([$q . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff, 'container-wide');
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if (!empty($_GET['scheduled'])): ?>
    <div class="banner banner-success">Schedule updated for document #<?= (int) $_GET['scheduled'] ?>.</div>
<?php endif ?>

<?php if (!empty($_GET['deleted'])): ?>
    <div class="banner banner-error">Document #<?= (int) $_GET['deleted'] ?> deleted.</div>
<?php endif ?>

<?php if (!empty($_GET['schedule_error'])): ?>
    <div class="banner banner-error">Invalid date/time for document #<?= (int) $_GET['schedule_error'] ?>. Schedule was not changed.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional — leave blank to publish immediately)</label>
            <div class="datetime-tz-row">
                <input type="datetime-local" id="publish_at" name="publish_at">
                <select name="tz" id="create_tz" class="tz-select" aria-label="Timezone">
                    <?php foreach ($TIMEZONES as $value => $label): ?>
                        <option value="<?= h($value) ?>"<?= $value === 'America/Chicago' ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by title…">
        <button type="submit" class="btn">Search</button>
        <?php if ($q !== ''): ?>
            <a href="/admin.php" class="btn-link">Clear</a>
        <?php endif ?>
    </form>
    <?php if ($q !== '' && empty($docs)): ?>
        <p class="empty">No documents matching "<?= h($q) ?>".</p>
    <?php elseif (empty($docs)): ?>
        <p class="empty">No documents yet.</p>
    <?php else: ?>

        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Readable ID</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Publish at</th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <?php $doc_tz = $d['publish_tz'] ?? 'America/Chicago'; ?>
                    <tr>
                        <td class="id">#<?= (int) $d['id'] ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><code><?= h($d['slug'] ?? '—') ?></code></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td>
                            <form method="post" class="schedule-form">
                                <input type="hidden" name="action" value="update_schedule">
                                <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                                <input type="datetime-local" name="publish_at"
                                       value="<?= h(utc_to_tz_input($d['publish_at'], $doc_tz)) ?>">
                                <select name="tz" class="tz-select tz-select-sm" aria-label="Timezone">
                                    <?php foreach ($TIMEZONES as $value => $label): ?>
                                        <option value="<?= h($value) ?>"<?= $value === $doc_tz ? ' selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <button type="submit" class="btn-link">Set</button>
                            </form>
                        </td>
                        <td class="nowrap"><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                        <td>
                            <form method="post" onsubmit="return confirm(<?= h(json_encode('Delete "' . $d['title'] . '"? This cannot be undone.')) ?>);">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="btn-link btn-link-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<script>
(function () {
    var browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    var createSel = document.getElementById('create_tz');
    if (createSel) {
        for (var i = 0; i < createSel.options.length; i++) {
            if (createSel.options[i].value === browserTz) {
                createSel.selectedIndex = i;
                break;
            }
        }
    }
})();
</script>

<?php render_footer(); ?>
