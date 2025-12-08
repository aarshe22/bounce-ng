<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Serve the SPA
$html = file_get_contents(__DIR__ . '/public/index.html');
// Add cache-busting to app.js to prevent stale JavaScript
$appJsMtime = filemtime(__DIR__ . '/public/app.js');
$html = str_replace('src="/app.js"', 'src="/app.js?v=' . $appJsMtime . '"', $html);
echo $html;

