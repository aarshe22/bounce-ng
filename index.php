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
echo $html;

