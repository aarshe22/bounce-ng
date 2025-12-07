<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, but log them

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

    if (!isset($_GET['code'])) {
        $authUrl = $provider->getAuthorizationUrl();
        $_SESSION['oauth2state'] = $provider->getState();
        header('Location: ' . $authUrl);
        exit;
    }
} catch (Exception $e) {
    error_log("Google OAuth Error: " . $e->getMessage());
    header('Location: /login.php?error=' . urlencode($e->getMessage()));
    exit;
}

if (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
    unset($_SESSION['oauth2state']);
    header('Location: /login.php?error=invalid_state');
    exit;
}

try {
    $user = $auth->handleOAuthCallback('google', $_GET['code']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['is_admin'] = $user['is_admin'];
    
    header('Location: /index.php');
    exit;
} catch (Exception $e) {
    error_log("Google OAuth Callback Error: " . $e->getMessage());
    header('Location: /login.php?error=' . urlencode($e->getMessage()));
    exit;
}

