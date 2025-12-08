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

// Inject user info into the page
$userName = $_SESSION['user_name'] ?? 'User';
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$html = str_replace('<span id="userName"></span>', '<span id="userName" data-is-admin="' . ($isAdmin ? '1' : '0') . '">' . htmlspecialchars($userName) . '</span>', $html);

// Hide User Management for non-admins
if (!$isAdmin) {
    $html = str_replace('id="userManagementBtn"', 'id="userManagementBtn" style="display: none;"', $html);
}

echo $html;

