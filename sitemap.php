<?php
/**
 * sitemap.php — Sitemap Generator
 *
 * Recursively scans data/ and writes a set of static sitemap XML files to the
 * site root, plus a master sitemap.xml (sitemap index) that references them.
 *
 * One sitemap "group" is created per top-level folder under data/ (loose
 * files directly inside data/ are grouped under "root"). Any group with more
 * than SITEMAP_CHUNK_SIZE files is split into multiple numbered sitemap
 * files, since a single sitemap should not list more than that many URLs.
 *
 * Protected: requires an active admin session from a.php (same pattern as
 * i.php, the search index builder). Run it by logging into a.php, then
 * clicking "🗺️ Generate Sitemap".
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

// Generate / retrieve CSRF token (shared with a.php / i.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Constants ────────────────────────────────────────────────────────────────
define('BASE_DATA_DIR', realpath(__DIR__ . '/data'));
define('SITEMAP_CHUNK_SIZE', 10000); // max URLs per sitemap file
define('MASTER_SITEMAP', __DIR__ . '/sitemap.xml');

$hiddenExtensions = ['sync.ffs_db', '.sync.ffs_db', 'sync.ffs_lock', '.DS_Store'];

// ── Helpers ──────────────────────────────────────────────────────────────────
// Mirrors the visibility rules in index.php (HIDE prefix, hidden extensions)
// so the sitemap never lists a file the public site itself would hide.
function sm_isHidden(string $name): bool {
    return stripos($name, 'HIDE') === 0;
}

function sm_isHiddenExt(string $filename, array $hidden): bool {
    foreach ($hidden as $ext) {
        if (strtolower(substr($filename, -strlen($ext))) === strtolower($ext)) return true;
        if (strtolower($filename) === strtolower($ext)) return true;
    }
    return false;
}

/** Recursively collect relative file paths (data/-relative) under $dir. */
function sm_scanFiles(string $dir, string $rel, array &$files, array $hiddenExt, array &$errors): void {
    $entries = @scandir($dir);
    if ($entries === false) {
        $errors[] = "Cannot read directory: /$rel";
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (sm_isHidden($entry)) continue;

        $full    = $dir . DIRECTORY_SEPARATOR . $entry;
        $relPath = ($rel !== '' ? $rel . '/' : '') . $entry;

        if (is_dir($full)) {
            sm_scanFiles($full, $relPath, $files, $hiddenExt, $errors);
        } elseif (is_file($full)) {
            if (sm_isHiddenExt($entry, $hiddenExt)) continue;
            $files[] = $relPath;
        }
    }
}

/** Turn a folder name into a safe, URL-friendly sitemap filename fragment. */
function sm_slugify(string $name): string {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'folder';
}

/** Percent-encode every path segment so the URL is valid inside XML <loc>. */
function sm_encodeParam(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function sm_buildUrlset(array $relPaths, string $baseUrl): string {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($relPaths as $rel) {
        $loc = $baseUrl . 'download.php?file=' . sm_encodeParam($rel);
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
        $xml .= "    <changefreq>yearly</changefreq>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>' . "\n";
    return $xml;
}

function sm_buildSitemapIndex(array $sitemapFiles, string $baseUrl, string $today): string {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($sitemapFiles as $file) {
        $xml .= "  <sitemap>\n";
        $xml .= '    <loc>' . htmlspecialchars($baseUrl . $file, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>$today</lastmod>\n";
        $xml .= "  </sitemap>\n";
    }
    $xml .= '</sitemapindex>' . "\n";
    return $xml;
}

$baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'christianpdf.com') . '/';

// ── Handle build request ─────────────────────────────────────────────────────
$built       = false;
$buildError  = '';
$buildErrors = [];
$groups      = []; // label => ['files' => n, 'sitemaps' => [filenames]]
$totalFiles  = 0;
$totalMaps   = 0;
$elapsed     = 0;

// Existing master sitemap metadata (for display before a (re)build)
$existingMeta = null;
if (file_exists(MASTER_SITEMAP)) {
    $existingMeta = ['generated' => date('Y-m-d H:i:s', filemtime(MASTER_SITEMAP))];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'build') {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('CSRF validation failed.');
    }

    $timeStart = microtime(true);

    // Remove sitemap files from any previous run so renamed/removed folders
    // don't leave stale sitemap-*.xml files behind.
    foreach (glob(__DIR__ . '/sitemap-*.xml') ?: [] as $stale) {
        @unlink($stale);
    }

    // Build one bucket per top-level folder under data/, plus a "root"
    // bucket for any loose files directly inside data/.
    $buckets  = []; // label => [relative file paths]
    $rootFiles = [];

    $topEntries = BASE_DATA_DIR ? (@scandir(BASE_DATA_DIR) ?: []) : [];
    foreach ($topEntries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (sm_isHidden($entry)) continue;

        $full = BASE_DATA_DIR . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            $bucketFiles = [];
            sm_scanFiles($full, $entry, $bucketFiles, $hiddenExtensions, $buildErrors);
            if (!empty($bucketFiles)) {
                $buckets[$entry] = $bucketFiles;
            }
        } elseif (is_file($full)) {
            if (sm_isHiddenExt($entry, $hiddenExtensions)) continue;
            $rootFiles[] = $entry;
        }
    }
    if (!empty($rootFiles)) {
        $buckets['(root)'] = $rootFiles;
    }

    // Write one or more sitemap files per bucket, chunked at SITEMAP_CHUNK_SIZE.
    $usedSlugs      = [];
    $allSitemapFiles = [];

    foreach ($buckets as $label => $relPaths) {
        $slug = sm_slugify($label);
        while (in_array($slug, $usedSlugs, true)) {
            $slug .= '-2';
        }
        $usedSlugs[] = $slug;

        $chunks = array_chunk($relPaths, SITEMAP_CHUNK_SIZE);
        $files  = [];

        foreach ($chunks as $i => $chunk) {
            $filename = 'sitemap-' . $slug . '-' . ($i + 1) . '.xml';
            $xml      = sm_buildUrlset($chunk, $baseUrl);
            if (@file_put_contents(__DIR__ . '/' . $filename, $xml, LOCK_EX) === false) {
                $buildErrors[] = "Could not write $filename";
                continue;
            }
            $files[] = $filename;
            $allSitemapFiles[] = $filename;
        }

        $groups[$label] = ['files' => count($relPaths), 'sitemaps' => $files];
        $totalFiles += count($relPaths);
    }

    $totalMaps = count($allSitemapFiles);

    // Write the master sitemap index referencing every sub-sitemap.
    $today = date('Y-m-d');
    $indexXml = sm_buildSitemapIndex($allSitemapFiles, $baseUrl, $today);
    if (@file_put_contents(MASTER_SITEMAP, $indexXml, LOCK_EX) === false) {
        $buildError = 'Could not write sitemap.xml. Check file permissions.';
    } else {
        $built = true;
        $existingMeta = ['generated' => date('Y-m-d H:i:s')];
    }

    $elapsed = round(microtime(true) - $timeStart, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sitemap Generator</title>
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
        .group-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: .85rem; }
        .group-table th, .group-table td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #eef2f5; }
        .group-table th { color: #7f8c8d; font-weight: 600; font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; }
        .group-table a { color: #2980b9; text-decoration: none; margin-right: 8px; }
        .group-table a:hover { text-decoration: underline; }
        code { background: #f4f6f9; padding: 2px 6px; border-radius: 4px; font-size: .88rem; }
    </style>
</head>
<body>

<div style="margin-bottom:18px;">
    <a href="a.php" style="color:#2980b9;font-size:.85rem;text-decoration:none;">← Back to Admin</a>
</div>

<div class="card">
    <h2>🗺️ Sitemap Generator</h2>
    <p style="font-size:.88rem;color:#7f8c8d;margin-bottom:20px;">
        Scans all files inside <code>data/</code> recursively and writes a sitemap file per top-level folder,
        plus a master <code>sitemap.xml</code> that lists them all. Folders with more than
        <?= number_format(SITEMAP_CHUNK_SIZE) ?> files are automatically split across multiple sitemap files.<br>
        Re-run this any time you add, rename, or delete files.
    </p>

    <?php if ($built): ?>
        <div class="alert alert-success">
            ✅ Sitemap built successfully in <?= $elapsed ?>s.
        </div>
        <div class="stat-grid">
            <div class="stat"><span class="val"><?= number_format($totalFiles) ?></span><span class="lbl">Files included</span></div>
            <div class="stat"><span class="val"><?= count($groups) ?></span><span class="lbl">Folders</span></div>
            <div class="stat"><span class="val"><?= number_format($totalMaps) ?></span><span class="lbl">Sitemap files</span></div>
            <div class="stat"><span class="val"><?= $elapsed ?>s</span><span class="lbl">Time taken</span></div>
        </div>

        <p style="font-size:.88rem;margin-bottom:6px;">
            🔗 Master sitemap:
            <a href="sitemap.xml" target="_blank"><?= htmlspecialchars($baseUrl) ?>sitemap.xml</a>
        </p>

        <table class="group-table">
            <thead>
                <tr><th>Folder</th><th>Files</th><th>Sitemap file(s)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $label => $g): ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td><?= number_format($g['files']) ?></td>
                        <td>
                            <?php foreach ($g['sitemaps'] as $f): ?>
                                <a href="<?= htmlspecialchars($f) ?>" target="_blank"><?= htmlspecialchars($f) ?></a>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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
        </div>
        <p style="font-size:.88rem;margin-bottom:16px;">
            🔗 Current master sitemap:
            <a href="sitemap.xml" target="_blank"><?= htmlspecialchars($baseUrl) ?>sitemap.xml</a>
        </p>
    <?php elseif (!$existingMeta && !$built): ?>
        <p style="font-size:.88rem;color:#e74c3c;margin-bottom:16px;">⚠️ No sitemap found yet. Build it now.</p>
    <?php endif; ?>

    <form method="POST" action="sitemap.php">
        <input type="hidden" name="action"     value="build">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn-build">
            <?= $existingMeta ? '🔄 Rebuild Sitemap' : '🗺️ Build Sitemap' ?>
        </button>
    </form>

    <a href="index.php" class="btn-back" target="_blank">👁️ View public site →</a>
</div>

</body>
</html>
