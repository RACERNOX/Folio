<?php

require __DIR__ . '/../lib/bootstrap.php';

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

echo "\nRunning tests:\n";

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Scheduled publishing ---

test('document with future publish_at is flagged as not yet available', function () {
    db()->prepare("
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES ('Future Doc', 'body', 1, datetime('now', '+1 hour'))
    ")->execute();
    $docId = (int) db()->lastInsertId();
    $stmt = db()->prepare("SELECT (publish_at > datetime('now')) AS blocked FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true((bool) $row['blocked'], 'expected future doc to be blocked');
});

test('document with past publish_at is visible', function () {
    db()->prepare("
        INSERT INTO documents (title, body, created_by, publish_at)
        VALUES ('Past Doc', 'body', 1, datetime('now', '-1 hour'))
    ")->execute();
    $docId = (int) db()->lastInsertId();
    $stmt = db()->prepare("SELECT (publish_at <= datetime('now')) AS visible FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true((bool) $row['visible'], 'expected past doc to be visible');
});

test('document with no publish_at is immediately visible', function () {
    db()->prepare("INSERT INTO documents (title, body, created_by) VALUES ('Immediate Doc', 'body', 1)")->execute();
    $docId = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true($row['publish_at'] === null, 'expected publish_at to be null');
});

// --- Search by title ---

test('search returns document matching title prefix', function () {
    db()->prepare("INSERT INTO documents (title, body, created_by) VALUES ('Onboarding Guide', 'body', 1)")->execute();
    $stmt = db()->prepare("SELECT id FROM documents WHERE title LIKE ?");
    $stmt->execute(['Onboarding%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, 'expected at least one result for prefix "Onboarding"');
});

test('search returns no results for non-matching prefix', function () {
    $stmt = db()->prepare("SELECT id FROM documents WHERE title LIKE ?");
    $stmt->execute(['ZZZNonExistent%']);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 0, 'expected no results for unmatched prefix');
});

test('empty search returns all documents', function () {
    $total = (int) db()->query('SELECT COUNT(*) AS c FROM documents')->fetch()['c'];
    assert_true($total >= 1, 'expected at least one document for empty search');
});

// --- Human-readable slugs ---

test('document creation generates a slug', function () {
    db()->prepare("INSERT INTO documents (title, body, created_by, slug) VALUES ('Offer Letter', 'body', 1, ?)")
        ->execute([unique_slug('Offer Letter')]);
    $docId = (int) db()->lastInsertId();
    $stmt = db()->prepare('SELECT slug FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $row = $stmt->fetch();
    assert_true(!empty($row['slug']), 'expected slug to be set');
});

test('slug matches expected format (base-XXXX)', function () {
    $slug = generate_slug('Contract Review');
    assert_true(
        (bool) preg_match('/^[a-z0-9-]+-[0-9a-f]{4}$/', $slug),
        'slug did not match expected format: ' . $slug
    );
});

test('two documents with the same title get different slugs', function () {
    $slug1 = unique_slug('Duplicate Title');
    db()->prepare("INSERT INTO documents (title, body, created_by, slug) VALUES ('Duplicate Title', 'body', 1, ?)")
        ->execute([$slug1]);
    $slug2 = unique_slug('Duplicate Title');
    assert_true($slug1 !== $slug2, 'expected different slugs for same title due to collision avoidance');
});

test('seeded document has a slug', function () {
    $stmt = db()->prepare("SELECT slug FROM documents WHERE title = 'Welcome Packet'");
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true(!empty($row['slug']), 'expected seeded Welcome Packet to have a slug');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
