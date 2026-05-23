<?php
// download.php — Force-download files from the data/ directory

// Resolve base data directory
$baseDir = realpath(__DIR__ . '/data');

// Get and sanitise the requested file path
$requestedFile = isset($_GET['file']) ? $_GET['file'] : '';
$requestedFile = str_replace("\0", '', $requestedFile);

// Resolve full path
$fullPath = realpath($baseDir . DIRECTORY_SEPARATOR . ltrim($requestedFile, '/\\'));

// Security: must be inside data/ and must be a file
if (
    $fullPath === false ||
    strpos($fullPath, $baseDir) !== 0 ||
    !is_file($fullPath)
) {
    http_response_code(404);
    exit('File not found.');
}

// Get filename and MIME type
$filename = basename($fullPath);
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mime     = finfo_file($finfo, $fullPath);
finfo_close($finfo);

// Fallback MIME
if (!$mime) {
    $mime = 'application/octet-stream';
}

// Build Content-Disposition header with proper UTF-8 filename support (RFC 6266)
// filename*=UTF-8''<percent-encoded> is understood by all modern browsers
// The plain filename= fallback is for very old clients
$encodedFilename = rawurlencode($filename);                  // percent-encode for filename*
$asciiFilename   = preg_replace('/[^\x20-\x7E]/', '_', $filename); // ASCII-safe fallback
$contentDisposition = 'attachment;'
    . ' filename="' . addslashes($asciiFilename) . '";'
    . ' filename*=UTF-8\'\'' . $encodedFilename;

// Send headers to force download
header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $contentDisposition);
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($fullPath));

// Flush output buffer and stream file
ob_clean();
flush();
readfile($fullPath);
exit;
