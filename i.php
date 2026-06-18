<?php
/**
 * i.php — Search Index Builder
 *
 * Scans the data/ directory recursively and writes search-index.json.
 * Protected: requires an active admin session from a.php.
 *
 * Usage: log in via a.php, then visit i.php and click "Build Index".
 */

// Share the admin session with a.php
session_name('admin_sess');
session_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache');
header('Referrer-Policy: same-origin');

// Require admin login
if (empty($_SESSION['admin_ok']) || $_SESSION['admin_ok'] !== true) {
    header('Location: a.php');
    exit;
}

// Generate / retrieve CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Constants ────────────────────────────────────────────────────────────────
define('BASE_DATA_DIR', realpath(__DIR__ . '/data'));

$indexFile        = __DIR__ . '/search-index.json';
$hiddenExtensions = ['sync.ffs_db', '.sync.ffs_db', 'sync.ffs_lock', '.DS_Store'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function idx_isHidden(string $name): bool {
    return stripos($name, 'HIDE') === 0;
}

function idx_isHiddenExt(string $filename, array $hidden): bool {
    foreach ($hidden as $ext) {
        if (strtolower(substr($filename, -strlen($ext))) === strtolower($ext)) return true;
        if (strtolower($filename) === strtolower($ext)) return true;
    }
    return false;
}

/**
 * Recursively scan data/ and populate $items.
 * Mirrors the visibility rules in index.php (HIDE prefix, hidden extensions).
 */
function idx_scan(string $dir, string $rel, array &$items, array &$errors, array $hiddenExt): void {
    $entries = @scandir($dir);
    if ($entries === false) {
        $errors[] = "Cannot read directory: /$rel";
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (idx_isHidden($entry)) continue;

        $full    = $dir . DIRECTORY_SEPARATOR . $entry;
        $relPath = ($rel !== '' ? $rel . '/' : '') . $entry;

        if (is_dir($full)) {
            $items[] = ['n' => $entry, 'p' => $relPath, 't' => 'd'];
            idx_scan($full, $relPath, $items, $errors, $hiddenExt);
        } elseif (is_file($full)) {
            if (idx_isHiddenExt($entry, $hiddenExt)) continue;
            $items[] = ['n' => $entry, 'p' => $relPath, 't' => 'f', 's' => filesize($full)];
        }
    }
}

// ── Load existing index metadata ─────────────────────────────────────────────
$existingMeta = null;
if (file_exists($indexFile)) {
    $raw = @file_get_contents($indexFile);
    if ($raw) {
        $dec = json_decode($raw, true);
        if (is_array($dec)) {
            $existingMeta = [
                'generated' => $dec['generated'] ?? '—',
                'count'     => $dec['count']     ?? 0,
            ];
        }
    }
}

// ── Handle build request ─────────────────────────────────────────────────────
$built     = false;
$buildError = '';
$dirCount  = 0;
$fileCount = 0;
$elapsed   = 0;
$buildErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $items = [];
    $timeStart = microtime(true);
    idx_scan(BASE_DATA_DIR, '', $items, $buildErrors, $hiddenExtensions);
    $elapsed   = round(microtime(true) - $timeStart, 2);

    $dirCount  = count(array_filter($items, fn($i) => $i['t'] === 'd'));
    $fileCount = count(array_filter($items, fn($i) => $i['t'] === 'f'));

    $payload = [
        'generated' => date('Y-m-d H:i:s'),
        'count'     => count($items),
        'items'     => $items,
    ];

    $written = @file_put_contents(
        $indexFile,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($written !== false) {
        $built = true;
        $existingMeta = ['generated' => $payload['generated'], 'count' => count($items)];
    } else {
        $buildError = 'Could not write search-index.json. Check file permissions.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Index Builder</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
    <style>
        body { max-width: 720px; margin: 40px auto; padding: 0 20px; }
        .card {
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 10px;
            padding: 28px 28px 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
            margin-bottom: 20px;
        }
        .card h2 { font-size: 1.1rem; color: #1a5276; margin-bottom: 14px; }
        .meta-row { display: flex; gap: 32px; flex-wrap: wrap; margin-bottom: 18px; }
        .meta-item { font-size: .88rem; color: #2c3e50; }
        .meta-item strong { display: block; font-size: 1.2rem; color: #1a5276; }
        .btn-build {
            display: inline-flex; align-items: center; gap: 8px;
            background: #1a5276; color: #fff; border: none;
            padding: 11px 26px; border-radius: 8px;
            font-size: .95rem; font-weight: 700; cursor: pointer;
            transition: background .18s;
        }
        .btn-build:hover { background: #154360; }
        .btn-back {
            display: inline-block; margin-top: 12px;
            color: #2980b9; font-size: .85rem; text-decoration: none;
        }
        .btn-back:hover { text-decoration: underline; }
        .alert { padding: 11px 16px; border-radius: 8px; font-size: .88rem; margin-bottom: 18px; }
        .alert-success { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }
        .alert-error   { background: #fdf2f2; color: #c0392b; border: 1px solid #f5c6cb; }
        .stat-grid { display: flex; gap: 24px; flex-wrap: wrap; margin: 16px 0 20px; }
        .stat { text-align: center; }
        .stat .val { font-size: 2rem; font-weight: 700; color: #1a5276; display: block; }
        .stat .lbl { font-size: .75rem; color: #7f8c8d; text-transform: uppercase; letter-spacing: .5px; }
        .err-list { margin-top: 12px; font-size: .82rem; color: #c0392b; }
        .err-list li { margin-bottom: 4px; }
        code { background: #f4f6f9; padding: 2px 6px; border-radius: 4px; font-size: .88rem; }
    </style>
</head>
<body>

<div style="margin-bottom:18px;">
    <a href="a.php" style="color:#2980b9;font-size:.85rem;text-decoration:none;">← Back to Admin</a>
</div>

<div class="card">
    <h2>⚙️ Search Index Builder</h2>
    <p style="font-size:.88rem;color:#7f8c8d;margin-bottom:20px;">
        Scans all files and folders inside <code>data/</code> and writes <code>search-index.json</code>,
        which powers the search bar on the public site.<br>
        Re-run this every time you add, rename, or delete files.
    </p>

    <?php if ($built): ?>
        <div class="alert alert-success">
            ✅ Index built successfully in <?= $elapsed ?>s.
        </div>
        <div class="stat-grid">
            <div class="stat"><span class="val"><?= number_format($fileCount) ?></span><span class="lbl">Files indexed</span></div>
            <div class="stat"><span class="val"><?= number_format($dirCount) ?></span><span class="lbl">Folders indexed</span></div>
            <div class="stat"><span class="val"><?= $elapsed ?>s</span><span class="lbl">Time taken</span></div>
        </div>
        <?php if (!empty($buildErrors)): ?>
            <ul class="err-list">
                <?php foreach ($buildErrors as $e): ?>
                    <li>⚠️ <?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($buildError): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($buildError) ?></div>
    <?php endif; ?>

    <?php if ($existingMeta && !$built): ?>
        <div class="meta-row">
            <div class="meta-item">Last built<strong><?= htmlspecialchars($existingMeta['generated']) ?></strong></div>
            <div class="meta-item">Entries<strong><?= number_format($existingMeta['count']) ?></strong></div>
        </div>
    <?php elseif (!$existingMeta && !$built): ?>
        <p style="font-size:.88rem;color:#e74c3c;margin-bottom:16px;">⚠️ No index found. Build it now to enable search.</p>
    <?php endif; ?>

    <form method="POST" action="i.php">
        <input type="hidden" name="action"     value="build">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-build">
            <?= $existingMeta ? '🔄 Rebuild Index' : '⚙️ Build Index' ?>
        </button>
    </form>

    <a href="index.php" class="btn-back" target="_blank">👁️ View public site →</a>
</div>

</body>
</html>
