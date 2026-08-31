<?php
// X-Frame-Options is controlled entirely by .htaccess (SetEnvIf + mod_headers),
// which reliably strips any server-injected header and applies SAMEORIGIN only
// for non-embed pages. No PHP header() call is needed here.

require_once __DIR__ . '/counter.php';

// Security: resolve the base data directory (real path)
$baseDir = realpath(__DIR__ . '/data');

// Get requested path from query string, sanitize it
$requestedPath = isset($_GET['path']) ? $_GET['path'] : '';

// Sanitize: strip any null bytes, then resolve
$requestedPath = str_replace("\0", '', $requestedPath);

// Build the full path and resolve it
$fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . ltrim($requestedPath, '/\\'));

// Security check: ensure the resolved path is within the base directory
if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
    $fullPath = $baseDir;
    $requestedPath = '';
}

// Hidden file types
$hiddenExtensions = ['sync.ffs_db', '.sync.ffs_db', 'sync.ffs_lock', '.DS_Store'];

// Helper: check if a name starts with HIDE
function isHidden($name) {
    return stripos($name, 'HIDE') === 0;
}

// Helper: check if file extension is hidden
function isHiddenExtension($filename, $hiddenExtensions) {
    foreach ($hiddenExtensions as $ext) {
        if (strtolower(substr($filename, -strlen($ext))) === strtolower($ext)) {
            return true;
        }
        if (strtolower($filename) === strtolower($ext)) {
            return true;
        }
    }
    return false;
}

// Helper: encode a file path for use in a URL query parameter,
// keeping Unicode (Tamil/Hindi etc.) characters human-readable
// while still encoding unsafe ASCII chars like / ? & space
function encodeFileParam($path) {
    return preg_replace_callback('/%([0-9A-F]{2})/i', function($m) {
        // Restore bytes > 0x7F (multi-byte UTF-8 sequences) back to raw chars
        return hexdec($m[1]) > 0x7F ? chr(hexdec($m[1])) : $m[0];
    }, rawurlencode($path));
}

// Helper: replace hyphens with spaces for display
function displayName($name) {
    return str_replace('-', ' ', $name);
}

// Helper: get file icon based on extension.
// Uses the PNG icon set in icons/ when one exists for the extension,
// falling back to an emoji for formats without a dedicated icon file.
function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Extensions with a matching file in icons/ (icons/<file>.png)
    $iconFiles = [
        'pdf'  => 'pdf',
        'doc'  => 'doc',
        'docx' => 'docx',
        'ppt'  => 'ppt',
        'pptx' => 'pptx',
        'xls'  => 'xls',
        'xlsx' => 'xlsx',
        'csv'  => 'csv',
        'txt'  => 'txt',
        'jpg'  => 'jpg',
        'jpeg' => 'jpg',
        'png'  => 'png',
        'mp3'  => 'mp3',
        'mp4'  => 'mp4',
        'zip'  => 'zip',
    ];
    if (isset($iconFiles[$ext])) {
        $file = $iconFiles[$ext];
        return '<img class="file-icon-img" src="icons/' . $file . '.png" alt="' . htmlspecialchars($ext) . ' file">';
    }

    // Formats without a dedicated icon file fall back to an emoji
    $icons = [
        'epub' => '📗',
        'mobi' => '📙',
        'apk'  => '📱',
        'rar'  => '🗜️',
    ];
    return isset($icons[$ext]) ? $icons[$ext] : '📄';
}

// Helper: human-readable file size
function humanFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// Helper: normalize a string for search matching
// Converts hyphens and underscores to spaces, lowercases, collapses whitespace
function normalizeForSearch($s) {
    $s = str_replace(['-', '_'], ' ', $s);
    $s = mb_strtolower($s, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $s));
}

// Build breadcrumb parts
$breadcrumbParts = [];
if ($requestedPath !== '') {
    $parts = explode('/', trim($requestedPath, '/'));
    $cumPath = '';
    foreach ($parts as $part) {
        $cumPath .= ($cumPath === '' ? '' : '/') . $part;
        $breadcrumbParts[] = ['name' => $part, 'path' => $cumPath];
    }
}

// Scan directory
$dirs  = [];
$files = [];

if (is_dir($fullPath)) {
    $items = scandir($fullPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (isHidden($item)) continue;

        $itemFullPath = $fullPath . DIRECTORY_SEPARATOR . $item;
        $itemRelPath  = ($requestedPath !== '' ? $requestedPath . '/' : '') . $item;

        if (is_dir($itemFullPath)) {
            $dirs[] = ['name' => $item, 'path' => $itemRelPath];
        } elseif (is_file($itemFullPath)) {
            if (isHiddenExtension($item, $hiddenExtensions)) continue;
            $files[] = [
                'name' => $item,
                'path' => $itemRelPath,
                'size' => humanFileSize(filesize($itemFullPath)),
            ];
        }
    }
}

// Sort alphabetically
usort($dirs,  fn($a, $b) => strcasecmp($a['name'], $b['name']));
usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Search
$searchQuery   = trim($_GET['q'] ?? '');
$isSearch      = $searchQuery !== '';
$indexBuilt    = file_exists(__DIR__ . '/search-index.json');
$searchResults = [];

if ($isSearch && $indexBuilt) {
    $rawIndex = @file_get_contents(__DIR__ . '/search-index.json');
    $idx      = $rawIndex ? json_decode($rawIndex, true) : null;
    if ($idx && !empty($idx['items'])) {
        $normalizedQ = normalizeForSearch($searchQuery);
        // Split into words so "Tamil Bible" matches "Tamil-Bible.pdf"
        $words = array_values(array_filter(explode(' ', $normalizedQ), fn($w) => $w !== ''));
        foreach ($idx['items'] as $item) {
            // Search against the filename (without extension) plus every path segment
            $target = normalizeForSearch(pathinfo($item['n'], PATHINFO_FILENAME))
                    . ' '
                    . normalizeForSearch(str_replace('/', ' ', $item['p']));
            $match = true;
            foreach ($words as $word) {
                if (!str_contains($target, $word)) { $match = false; break; }
            }
            if ($match) $searchResults[] = $item;
        }
    }
}

// Page meta
$pageTitle = 'Free Christian E-Books and PDFs';
$metaTitle = $pageTitle;
if ($requestedPath !== '') {
    // Build title from path parts in reverse order: deepest › ... › root › $pageTitle
    $pathParts = array_map('displayName', explode('/', trim($requestedPath, '/')));
    $pathParts  = array_reverse($pathParts);
    $metaTitle  = implode(' - ', $pathParts) . ' - ' . $pageTitle;
}
$metaDesc  = 'Browse and download free Christian e-books, PDFs, audio Bibles, and more. A free repository for all Christian book readers in multiple languages like Tamil, Hindi, Kannada, English, Hebrew, Greek, etc.';
$canonical = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'christianpdf.com') . '/';
if ($requestedPath !== '') {
    $canonical .= '?path=' . rawurlencode($requestedPath);
}

// Embed mode: hide everything except <main> and copy-link-wrap
$isEmbed = isset($_GET['embed']) && $_GET['embed'] === '1';
$embedSuffix = $isEmbed ? '&embed=1' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $metaTitle ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="<?= $metaTitle ?>">
    <meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= $pageTitle ?>",
      "url": "https://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'christianpdf.com') ?>/"
    }
    </script>

    <!-- PWA -->
    <link rel="manifest" href="manifest.json?v=<?= filemtime(__DIR__ . '/manifest.json') ?>">
    <meta name="theme-color" content="#1a5276">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ChristianPDF">
    <link rel="apple-touch-icon" href="icons/icon-192.png">

    <link rel="stylesheet" href="styles.css?v=<?= filemtime(__DIR__ . '/styles.css') ?>">
</head>
<body>

<?php if (!$isEmbed): ?>
<!-- PWA Install Banner -->
<div id="pwa-banner" class="pwa-banner" style="display:none;" role="banner" aria-live="polite">
    <div class="pwa-banner-inner">
        <img src="icons/icon-192.png" alt="ChristianPDF icon" class="pwa-banner-icon">
        <div class="pwa-banner-text">
            <strong>Install as App</strong>
            <span>Add to your home screen for quick access — works offline too!</span>
        </div>
        <button id="pwa-install-btn" class="pwa-btn-install" aria-label="Install app">Install</button>
        <button id="pwa-dismiss-btn" class="pwa-btn-dismiss" aria-label="Dismiss install banner">✕</button>
    </div>
</div>

<header>
    <div class="header-inner">
        <h1>✝️ <?= htmlspecialchars($pageTitle) ?></h1>
        <p class="tagline">Browse &amp; download free Christian books, PDFs, audio Bibles and more in multiple languages like Tamil, Hindi, Kannada, English, Hebrew, Greek, etc</p>
    </div>
</header>

<!-- Breadcrumb -->
<nav class="breadcrumb" aria-label="Breadcrumb">
    <div class="breadcrumb-inner">
    <a href="index.php">🏠 Home</a>
    <?php foreach ($breadcrumbParts as $crumb): ?>
        <span class="sep">›</span>
        <?php if ($crumb === end($breadcrumbParts)): ?>
            <span class="current"><?= htmlspecialchars(displayName($crumb['name'])) ?></span>
        <?php else: ?>
            <a href="index.php?path=<?= encodeFileParam($crumb['path']) ?>"><?= htmlspecialchars(displayName($crumb['name'])) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</nav>

<!-- Search bar -->
<div class="search-wrap">
    <form class="search-form" action="index.php" method="get" role="search">
        <input type="search" name="q" class="search-input"
               placeholder="Search files and folders…"
               value="<?= htmlspecialchars($searchQuery) ?>"
               autocomplete="off" aria-label="Search">
        <button type="submit" class="search-btn">🔍 Search</button>
        <?php if ($isSearch): ?>
            <a href="index.php" class="search-clear" aria-label="Clear search">✕ Clear</a>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<main>

<?php if ($isSearch): ?>

    <div class="file-list">
        <div class="file-list-header">
            <span>🔍 Results for "<?= htmlspecialchars($searchQuery) ?>"</span>
            <span><?= count($searchResults) ?> result<?= count($searchResults) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (!$indexBuilt): ?>
            <div class="empty-state">
                <span>⚙️</span>
                Search index not built yet. The admin needs to run the indexer at <code>i.php</code>.
            </div>

        <?php elseif (empty($searchResults)): ?>
            <div class="empty-state">
                <span>🔍</span>
                No results found for "<?= htmlspecialchars($searchQuery) ?>".
                <br><a href="index.php" style="color:var(--accent);font-size:.85rem;">Browse all categories</a>
            </div>

        <?php else: ?>
            <?php foreach ($searchResults as $res):
                $resIsDir   = $res['t'] === 'd';
                $resIcon    = $resIsDir ? '<img class="file-icon-img" src="icons/folder.png" alt="folder">' : getFileIcon($res['n']);
                $resName    = displayName(pathinfo($res['n'], $resIsDir ? PATHINFO_BASENAME : PATHINFO_FILENAME));
                $resExt     = !$resIsDir ? pathinfo($res['n'], PATHINFO_EXTENSION) : '';
                $resDirHref = 'index.php?path=' . encodeFileParam($res['p']) . $embedSuffix;
                $resDlHref  = 'download.php?file=' . encodeFileParam($res['p']);
                $resParent  = dirname($res['p']);
                $resParent  = ($resParent === '.' || $resParent === '') ? '' : $resParent;
                $resCrumbs  = $resParent !== '' ? array_map('displayName', explode('/', $resParent)) : [];
            ?>
            <?php if ($resIsDir): ?>
                <a class="file-item" href="<?= $resDirHref ?>" title="<?= htmlspecialchars($res['n']) ?>">
                    <span class="icon"><?= $resIcon ?></span>
                    <span class="name">
                        <?= htmlspecialchars($resName) ?>
                        <?php if (!empty($resCrumbs)): ?>
                            <span class="result-path">📂 <?= htmlspecialchars(implode(' › ', $resCrumbs)) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php else: ?>
                <div class="file-item file-item-detailed" title="<?= htmlspecialchars($res['n']) ?>">
                    <span class="icon"><?= $resIcon ?></span>
                    <div class="file-item-content">
                        <div class="file-item-top">
                            <span class="name">
                                <?= htmlspecialchars($resName) ?><?php if ($resExt): ?><span style="color:var(--muted);font-size:.8em">.<?= htmlspecialchars($resExt) ?></span><?php endif; ?>
                                <?php if (!empty($resCrumbs)): ?>
                                    <span class="result-path">📂 <?= htmlspecialchars(implode(' › ', $resCrumbs)) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="file-item-links">
                            <a class="file-action-link download-link" href="<?= $resDlHref ?>">⬇️ Download</a>
                            <?php if (isset($res['s'])): ?>
                                <span class="meta"><?= humanFileSize((int)$res['s']) ?></span>
                            <?php endif; ?>
                            <span class="file-action-link report-link" data-report-path="<?= htmlspecialchars($res['p']) ?>" data-report-name="<?= htmlspecialchars(displayName($res['n'])) ?>">Report a copyright issue</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="file-list">
        <div class="file-list-header">
            <span>
                <?php if ($requestedPath !== ''): ?>
                    📂 <?= htmlspecialchars(displayName(basename($requestedPath))) ?>
                <?php else: ?>
                    📚 All Categories
                <?php endif; ?>
            </span>
            <span><?= count($dirs) ?> folder<?= count($dirs) !== 1 ? 's' : '' ?>, <?= count($files) ?> file<?= count($files) !== 1 ? 's' : '' ?></span>
        </div>

        <?php
        // Build parent path for ".." link
        if ($requestedPath !== '') {
            $parentPath = dirname($requestedPath);
            $parentUrl  = ($parentPath === '.' || $parentPath === '')
                ? 'index.php' . ($isEmbed ? '?embed=1' : '')
                : 'index.php?path=' . encodeFileParam($parentPath) . $embedSuffix;
        }
        ?>

        <?php if ($requestedPath !== ''): ?>
            <a class="file-item file-item-up" href="<?= $parentUrl ?>">
                <span class="icon">⬆️</span>
                <span class="name">.. (Up one level)</span>
            </a>
        <?php endif; ?>

        <?php if (empty($dirs) && empty($files)): ?>
            <div class="empty-state">
                <span>📭</span>
                This folder is empty.
            </div>

        <?php else: ?>

            <?php if (!empty($dirs)): ?>
                <div class="section-label">Folders</div>
                <?php foreach ($dirs as $dir): ?>
                    <a class="file-item"
                       href="index.php?path=<?= encodeFileParam($dir['path']) . $embedSuffix ?>"
                       title="<?= htmlspecialchars(displayName($dir['name'])) ?>">
                        <span class="icon"><img class="file-icon-img" src="icons/folder.png" alt="folder"></span>
                        <span class="name"><?= htmlspecialchars(displayName($dir['name'])) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($files)): ?>
                <div class="section-label">Files</div>
                <?php foreach ($files as $file): ?>
                    <div class="file-item file-item-detailed" title="<?= htmlspecialchars(displayName($file['name'])) ?>">
                        <span class="icon"><?= getFileIcon($file['name']) ?></span>
                        <div class="file-item-content">
                            <div class="file-item-top">
                                <span class="name"><?= htmlspecialchars(displayName(pathinfo($file['name'], PATHINFO_FILENAME))) ?><span style="color:var(--muted);font-size:.8em">.<?= htmlspecialchars(pathinfo($file['name'], PATHINFO_EXTENSION)) ?></span></span>
                            </div>
                            <div class="file-item-links">
                                <a class="file-action-link download-link" href="download.php?file=<?= encodeFileParam($file['path']) ?>">⬇️ Download</a>
                                <span class="meta"><?= $file['size'] ?></span>
                                <span class="file-action-link report-link" data-report-path="<?= htmlspecialchars($file['path']) ?>" data-report-name="<?= htmlspecialchars(displayName($file['name'])) ?>">Report a copyright issue</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>
    </div>

<?php endif; ?>

</main>

<div class="copy-link-wrap">
    <button id="copy-link-btn" class="copy-link-btn" onclick="copyPageLink(this)">
        🔗 Copy Link
    </button>
    <span id="copy-link-msg" class="copy-link-msg" aria-live="polite"></span>
</div>

<!-- Report Copyright Violation modal -->
<div id="report-modal-overlay" class="report-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="report-modal-title">
    <div class="report-modal">
        <button type="button" class="report-modal-close" id="report-modal-close" aria-label="Close">✕</button>
        <h3 id="report-modal-title">🚩 Report Copyright Violation</h3>

        <div class="report-modal-warning">
            ⚠️ By submitting this form, the file will be <strong>DELETED</strong> from our server.
            Please be careful and do not report unwantedly.
        </div>

        <p class="report-modal-file" id="report-modal-file"></p>

        <form id="report-form" novalidate>
            <input type="hidden" name="file_path" id="report-file-path" value="">

            <div id="report-form-fields">
                <div class="report-form-group">
                    <label for="report-name">Your Name <span class="req">*</span></label>
                    <input type="text" id="report-name" name="name" required maxlength="150">
                </div>

                <div class="report-form-group">
                    <label for="report-mobile">Your Mobile / WhatsApp <span class="req">*</span></label>
                    <input type="tel" id="report-mobile" name="mobile" required maxlength="20">
                </div>

                <div class="report-form-group">
                    <label for="report-email">Your Email <span class="opt">(optional)</span></label>
                    <input type="email" id="report-email" name="email" maxlength="150">
                </div>

                <div class="report-form-group">
                    <label>Do you own this book/material? <span class="req">*</span></label>
                    <div class="report-radio-row">
                        <label class="report-radio"><input type="radio" name="owns" value="yes" required> Yes</label>
                        <label class="report-radio"><input type="radio" name="owns" value="no" required> No</label>
                    </div>
                </div>

                <div class="report-form-group" id="report-details-group" style="display:none;">
                    <label for="report-details">Share more details <span class="req">*</span></label>
                    <textarea id="report-details" name="details" rows="4" maxlength="3000" placeholder="Please describe the copyright issue…"></textarea>
                </div>

                <div class="report-modal-actions">
                    <button type="button" class="btn-report-confirm-cancel" id="report-modal-cancel">Cancel</button>
                    <button type="submit" class="btn-report-cancel" id="report-modal-submit">Submit</button>
                </div>
            </div>

            <div id="report-confirm-step" style="display:none;">
                <p class="report-confirm-text">Do you want to DELETE this book from this server?</p>
                <div class="report-modal-actions">
                    <button type="button" class="btn-report-confirm-cancel" id="report-confirm-cancel">Cancel</button>
                    <button type="button" class="btn-report-cancel" id="report-confirm-yes">Yes</button>
                </div>
            </div>

            <div class="report-modal-msg" id="report-modal-msg" aria-live="polite"></div>
        </form>
    </div>
</div>

<?php if (!$isEmbed): ?>
<footer>
    <div class="footer-inner">
        <div class="visitors">👥 Visitors: <span><?= number_format((int)$visitors1) ?></span></div>
        <p>We do not own any of these books, it was collected from various sources, internet, WhatsApp, Cloud Drives, etc. We just maintain the repository at one place.</p>
        <p>This site is hosted by investing huge cost, but served free of cost for all the christian book readers!</p>
        <p>If you want your book to be removed from this repository, send an email to <a href="mailto:admin@christianpdf.com">admin@christianpdf.com</a></p>
        <p class="footer-install-wrap">
            <a href="#" id="pwa-footer-install" class="footer-install-link" style="display:none;">
                📲 Install ChristianPDF App on your device
            </a>
        </p>
    </div>
    <div style="position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; opacity: 0; pointer-events: none;" aria-hidden="true">
    <a href="./bot.php" tabindex="-1">.</a>
</div>
</footer>
<?php endif; ?>

<script>
// ── Capture beforeinstallprompt ASAP (before any deferred code) ──
window.__pwaInstallEvent = null;
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    window.__pwaInstallEvent = e;
    // Show banner if the DOM is already ready
    if (document.readyState !== 'loading') {
        window.__pwaShowBanner && window.__pwaShowBanner();
    }
});

// ── Copy Link ──
function copyPageLink(btn) {
    const decodedUrl = decodeURIComponent(window.location.href);
    navigator.clipboard.writeText(decodedUrl).then(() => {
        const msg = document.getElementById('copy-link-msg');
        btn.textContent = '✅ Copied!';
        msg.textContent = decodedUrl;
        setTimeout(() => {
            btn.textContent = '🔗 Copy Link';
            msg.textContent = '';
        }, 2500);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = decodeURIComponent(window.location.href);
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        const msg = document.getElementById('copy-link-msg');
        btn.textContent = '✅ Copied!';
        msg.textContent = decodeURIComponent(window.location.href);
        setTimeout(() => {
            btn.textContent = '🔗 Copy Link';
            msg.textContent = '';
        }, 2500);
    });
}

(function () {
    'use strict';

    // ── Service Worker Registration ──
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then(reg => console.log('[PWA] SW registered:', reg.scope))
                .catch(err => console.warn('[PWA] SW registration failed:', err));
        });
    }

    // ── iOS Detection ──
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isInStandaloneMode = ('standalone' in window.navigator) && window.navigator.standalone;

    // ── Install Prompt Elements ──
    const banner     = document.getElementById('pwa-banner');
    const installBtn = document.getElementById('pwa-install-btn');
    const dismissBtn = document.getElementById('pwa-dismiss-btn');
    const footerLink = document.getElementById('pwa-footer-install');
    const bannerText = document.querySelector('.pwa-banner-text span');

    function showBanner() {
        banner.style.display = 'block';
        if (footerLink) footerLink.style.display = 'inline-block';
    }

    function hideBanner() {
        banner.style.display = 'none';
    }

    // ── Expose showBanner for early-captured event ──
    window.__pwaShowBanner = showBanner;

    // ── iOS: show manual install instructions ──
    if (isIos && !isInStandaloneMode) {
        if (bannerText) bannerText.textContent = 'Tap the Share button (⬆) then "Add to Home Screen" to install.';
        installBtn.style.display = 'none'; // hide the install button, can't trigger prompt on iOS
        showBanner();
    }

    // ── Android / Desktop Chrome: check if event already captured ──
    if (window.__pwaInstallEvent) {
        showBanner();
    }

    // ── Also listen in case it fires after DOM load ──
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        window.__pwaInstallEvent = e;
        showBanner();
    });

    function triggerInstall(e) {
        if (e) e.preventDefault();
        if (!window.__pwaInstallEvent) return;
        hideBanner();
        window.__pwaInstallEvent.prompt();
        window.__pwaInstallEvent.userChoice.then(choice => {
            console.log('[PWA] Install choice:', choice.outcome);
            window.__pwaInstallEvent = null;
            if (footerLink) footerLink.style.display = 'none';
        });
    }

    installBtn.addEventListener('click', triggerInstall);
    if (footerLink) footerLink.addEventListener('click', triggerInstall);

    dismissBtn.addEventListener('click', () => {
        hideBanner();
    });

    window.addEventListener('appinstalled', () => {
        hideBanner();
        if (footerLink) footerLink.style.display = 'none';
        console.log('[PWA] App installed successfully.');
    });
})();

// ── Report Copyright Violation ──
(function () {
    'use strict';

    const overlay      = document.getElementById('report-modal-overlay');
    const fileLabel     = document.getElementById('report-modal-file');
    const filePathInput = document.getElementById('report-file-path');
    const form           = document.getElementById('report-form');
    const msgBox         = document.getElementById('report-modal-msg');
    const submitBtn       = document.getElementById('report-modal-submit');
    const detailsGroup    = document.getElementById('report-details-group');
    const detailsInput    = document.getElementById('report-details');
    const formFieldsStep  = document.getElementById('report-form-fields');
    const confirmStep     = document.getElementById('report-confirm-step');
    const confirmYesBtn   = document.getElementById('report-confirm-yes');
    const confirmCancelBtn = document.getElementById('report-confirm-cancel');

    function showFormStep() {
        formFieldsStep.style.display = 'block';
        confirmStep.style.display = 'none';
    }

    function showConfirmStep() {
        formFieldsStep.style.display = 'none';
        confirmStep.style.display = 'block';
    }

    function openReportModal(path, name) {
        form.reset();
        detailsGroup.style.display = 'none';
        detailsInput.required = false;
        msgBox.textContent = '';
        msgBox.className = 'report-modal-msg';
        filePathInput.value = path;
        fileLabel.textContent = '📄 ' + name;
        showFormStep();
        overlay.classList.add('open');
        setTimeout(() => document.getElementById('report-name').focus(), 50);
    }

    function closeReportModal() {
        overlay.classList.remove('open');
    }

    document.querySelectorAll('.report-link').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openReportModal(this.dataset.reportPath, this.dataset.reportName);
        });
    });

    document.getElementById('report-modal-close').addEventListener('click', closeReportModal);
    document.getElementById('report-modal-cancel').addEventListener('click', closeReportModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeReportModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeReportModal();
    });

    form.querySelectorAll('input[name="owns"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const showDetails = this.value === 'no';
            detailsGroup.style.display = showDetails ? 'block' : 'none';
            detailsInput.required = showDetails;
        });
    });

    // Step 1: form passes native validation → ask for explicit confirmation
    // before anything is actually sent (submitting deletes the file).
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // The form carries novalidate so this handler has full control over
        // when validation runs — reportValidity() must be called explicitly,
        // otherwise required fields would never block the confirm step.
        if (!form.reportValidity()) {
            return;
        }

        msgBox.textContent = '';
        msgBox.className = 'report-modal-msg';
        showConfirmStep();
    });

    confirmCancelBtn.addEventListener('click', function () {
        closeReportModal();
    });

    // Step 2: user confirmed — actually submit the report.
    confirmYesBtn.addEventListener('click', function () {
        // Snapshot the form data first — disabled fields are excluded from FormData.
        const formData = new FormData(form);
        const formFields = form.querySelectorAll('input, textarea, button');
        formFields.forEach(function (el) { el.disabled = true; });
        confirmYesBtn.textContent = 'Submitting…';

        fetch('report.php', {
            method: 'POST',
            body: formData
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                msgBox.textContent = data.message || '';
                msgBox.className = 'report-modal-msg ' + (data.ok ? 'success' : 'error');
                if (data.ok) {
                    form.reset();
                    detailsGroup.style.display = 'none';
                    setTimeout(closeReportModal, 2200);
                } else {
                    showFormStep();
                }
            })
            .catch(function () {
                msgBox.textContent = 'Network error. Please try again.';
                msgBox.className = 'report-modal-msg error';
                showFormStep();
            })
            .finally(function () {
                formFields.forEach(function (el) { el.disabled = false; });
                confirmYesBtn.textContent = 'Yes';
            });
    });
})();
</script>

</body>
</html>

