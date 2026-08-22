<?php
/**
 * report.php — receives "Report Copyright Violation" submissions for individual
 * files and stores each one as its own JSON file under reports/.
 * Called via fetch() from index.php; always responds with JSON.
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

function fail(string $message): void {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

$baseDir = realpath(__DIR__ . '/data');

// Validate the reported file actually exists inside data/
$filePath = isset($_POST['file_path']) ? str_replace("\0", '', $_POST['file_path']) : '';
$fullPath = ($baseDir && $filePath !== '')
    ? realpath($baseDir . DIRECTORY_SEPARATOR . ltrim($filePath, '/\\'))
    : false;

if ($filePath === '' || $fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
    fail('Invalid file reference.');
}

$name    = trim($_POST['name']    ?? '');
$mobile  = trim($_POST['mobile']  ?? '');
$email   = trim($_POST['email']   ?? '');
$owns    = $_POST['owns']         ?? '';
$details = trim($_POST['details'] ?? '');

if ($name === '' || mb_strlen($name) > 150) {
    fail('Please enter your name.');
}
if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{6,20}$/', $mobile)) {
    fail('Please enter a valid mobile/WhatsApp number.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Please enter a valid email, or leave it blank.');
}
if (!in_array($owns, ['yes', 'no'], true)) {
    fail('Please answer whether you own this book/material.');
}
if ($owns === 'no' && $details === '') {
    fail('Please share more details.');
}
if (mb_strlen($details) > 3000) {
    fail('Details are too long.');
}

$reportsDir = __DIR__ . '/reports';
if (!is_dir($reportsDir) && !@mkdir($reportsDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error. Please try again later.']);
    exit;
}

$report = [
    'id'        => bin2hex(random_bytes(8)),
    'timestamp' => date('c'),
    'file_path' => $filePath,
    'file_name' => basename($fullPath),
    'name'      => $name,
    'mobile'    => $mobile,
    'email'     => $email,
    'owns'      => $owns,
    'details'   => $details,
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
];

$reportFile = $reportsDir . '/' . date('Ymd_His') . '_' . $report['id'] . '.json';

if (file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to save report. Please try again later.']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Thank you. Your report has been submitted and will be reviewed.']);
