<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;

try {
    $auth = new Auth();
    
    // Check if OAuth credentials are configured
    if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
        throw new Exception("Google OAuth credentials not configured. Please check your .env file.");
    }
    
    $provider = $auth->getGoogleProvider();
    $authUrl = $provider->getAuthorizationUrl();
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
    exit;
} catch (Exception $e) {
    error_log("Google OAuth Error: " . $e->getMessage());
    header('Location: /login.php?error=' . urlencode($e->getMessage()));
    exit;
}

