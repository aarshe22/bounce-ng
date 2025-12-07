<?php

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

// Database configuration
define('DB_PATH', __DIR__ . '/data/bounce_monitor.db');
define('DB_SEED_PATH', __DIR__ . '/data/seed.sql');

// Application configuration
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost:8000');
define('APP_SECRET', $_ENV['APP_SECRET'] ?? 'change-this-secret');

// OAuth configuration
define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI', $_ENV['GOOGLE_REDIRECT_URI'] ?? APP_URL . '/oauth-callback.php?provider=google');

define('MICROSOFT_CLIENT_ID', $_ENV['MICROSOFT_CLIENT_ID'] ?? '');
define('MICROSOFT_CLIENT_SECRET', $_ENV['MICROSOFT_CLIENT_SECRET'] ?? '');
define('MICROSOFT_REDIRECT_URI', $_ENV['MICROSOFT_REDIRECT_URI'] ?? APP_URL . '/oauth-callback.php?provider=microsoft');

// Test mode override (can still be in .env for convenience, but also stored in DB)
define('TEST_MODE_OVERRIDE_EMAIL', $_ENV['TEST_MODE_OVERRIDE_EMAIL'] ?? '');

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

