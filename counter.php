<?php
// counter.php

// List of common bot keywords in User-Agent
$botKeywords = [
    'bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'curl', 'python', 'wget', 'baiduspider', 'bingpreview', 'facebookexternalhit', 'pingdom'
];

// Get lowercase user agent
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

// Check if it's a bot
$isBot = false;
foreach ($botKeywords as $keyword) {
    if (strpos($userAgent, $keyword) !== false) {
        $isBot = true;
        break;
    }
}

$visitors1 = '1';

// If not a bot, increment the counter
if (!$isBot) {
    $counterFile = __DIR__ . '/counter.txt';
    
    // If file doesn’t exist, create it with 0
    if (!file_exists($counterFile)) {
        file_put_contents($counterFile, "0");
    }

    // Open file for reading and writing
    $fp = fopen($counterFile, "c+"); // c+ = read/write, create if not exists

    if (flock($fp, LOCK_EX)) { // lock file exclusively
        // Read current count
        $count = (int)fread($fp, filesize($counterFile));
    
        // Increment
        $count++;
    
        $visitors1 = $count;
    
        // Rewind and write new value
        ftruncate($fp, 0);  // clear file
        rewind($fp);
        fwrite($fp, (string)$count);
    
        fflush($fp);        // flush output
        flock($fp, LOCK_UN); // unlock
    } else {
        // Could not lock file (very rare)
        $count = -1;
    }

    fclose($fp);

}

?>