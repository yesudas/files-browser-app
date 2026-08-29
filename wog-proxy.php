<?php
/**
 * wog-proxy.php
 *
 * Upload this file to WordOfGod.in (NOT to ChristianPDF.com).
 * Point the iframe on WordOfGod.in to THIS file instead.
 *
 * Usage:
 *   <iframe src="/wog-proxy.php?path=Word-of-God-Releases" ...></iframe>
 *   <iframe src="/wog-proxy.php" ...></iframe>
 *
 * How it works:
 *   Fetches the embed page from ChristianPDF.com, rewrites all internal
 *   links and asset URLs to absolute form, then outputs the HTML.
 *   Because the iframe now loads from WordOfGod.in, there is no
 *   cross-origin X-Frame-Options conflict.
 */

// ── Source site ───────────────────────────────────────────────────
define('SOURCE_BASE', 'https://christianpdf.com');

// ── Build the upstream URL ────────────────────────────────────────
$path = isset($_GET['path']) ? trim($_GET['path']) : '';
$path = str_replace(["\0", '..'], '', $path); // basic sanitise

$upstreamUrl = SOURCE_BASE . '/index.php?embed=1';
if ($path !== '') {
    $upstreamUrl .= '&path=' . rawurlencode($path);
}

// ── Fetch the upstream page ───────────────────────────────────────
$html = '';
$error = '';

if (function_exists('curl_init')) {
    $ch = curl_init($upstreamUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'WordOfGod-Proxy/1.0',
        CURLOPT_ENCODING       => '',  // accept gzip/deflate
    ]);
    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = 'cURL error: ' . curl_error($ch);
    }
    curl_close($ch);
} elseif (ini_get('allow_url_fopen')) {
    $html = @file_get_contents($upstreamUrl);
    if ($html === false) $error = 'file_get_contents failed.';
} else {
    $error = 'Neither cURL nor allow_url_fopen is available on this server.';
}

if ($error) {
    http_response_code(502);
    echo htmlspecialchars($error);
    exit;
}

// ── Rewrite relative URLs → absolute ─────────────────────────────
// src="..." and href="..." that start with / or are bare filenames
// become https://christianpdf.com/...
$base = SOURCE_BASE;

// Convert relative hrefs and srcs to absolute
$html = preg_replace_callback(
    '/\b(src|href|action)=["\'](?!https?:\/\/|mailto:|#|javascript:)([^"\']*)["\']/',
    function ($m) use ($base) {
        $attr = $m[1];
        $url  = $m[2];
        if ($url === '') return $m[0];
        // Already absolute with // (protocol-relative)
        if (str_starts_with($url, '//')) return "$attr=\"https:$url\"";
        // Root-relative
        if (str_starts_with($url, '/')) return "$attr=\"$base$url\"";
        // Relative (no leading /) — prefix base + /
        return "$attr=\"$base/$url\"";
    },
    $html
);

// Rewrite download links: download.php → keep absolute
// (already handled above since download.php is a bare relative path)

// Rewrite navigation links inside the proxy:
// index.php?path=... → wog-proxy.php?path=...
$html = preg_replace_callback(
    '/\b(href|action)=["\']' . preg_quote($base, '/') . '\/index\.php([^"\']*)["\']/',
    function ($m) {
        $attr  = $m[1];
        $query = $m[2]; // e.g. ?path=Tamil-Christian-Books&embed=1
        // Strip embed=1 (proxy adds it automatically) and strip leading ?
        $query = preg_replace('/[?&]embed=1/', '', $query);
        $query = ltrim($query, '?&');
        $url   = 'wog-proxy.php' . ($query !== '' ? '?' . $query : '');
        return "$attr=\"$url\"";
    },
    $html
);

// ── Output ────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
// No X-Frame-Options = this page can be freely embedded in iframes on this domain
echo $html;
