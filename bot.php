<?php
/**
 * Bot Honeypot Trap
 * 
 * This file serves as a honeypot to detect and log bot activity.
 * Legitimate users should never access this page as it's hidden from view.
 * Any access to this page is logged with details for security monitoring.
 * 
 * Setup Instructions:
 * 1. Ensure bot.log file has write permissions: chmod 644 bot.log
 * 2. The honeypot link is automatically included in footer-links.php
 * 3. The link is hidden from users using CSS but accessible to bots
 * 
 * Log Format:
 * [Timestamp] ID: unique_hash | IP: ip_address | User-Agent: user_agent | Referer: referer
 * 
 * Features:
 * - Unique ID prevents duplicate logging of same bot
 * - Logs IP address for tracking
 * - Logs User-Agent for bot identification
 * - Logs Referer to see where bot came from
 * - No URL logging for privacy
 */

// Get bot information
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');
$referer = $_SERVER['HTTP_REFERER'] ?? 'direct';

// Create a unique identifier for this bot access
$uniqueId = md5($ip . $userAgent);

// Log file path
$logFile = __DIR__ . '/bot.log';

// Check if this bot has already been logged
$isDuplicate = false;
if (file_exists($logFile)) {
    $existingLogs = file_get_contents($logFile);
    if (strpos($existingLogs, $uniqueId) !== false) {
        $isDuplicate = true;
    }
}

// Only log if not a duplicate
if (!$isDuplicate) {
    $logEntry = sprintf(
        "[%s] ID: %s | IP: %s | User-Agent: %s | Referer: %s\n",
        $timestamp,
        $uniqueId,
        $ip,
        $userAgent,
        $referer
    );
    
    // Append to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// Return a minimal response to not alert sophisticated bots
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Page Not Found</title>
</head>
<body>
    <p>.</p>
</body>
</html>