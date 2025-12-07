<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';

use BounceNG\Auth;

try {
    $auth = new Auth();
    
    // Check if OAuth credentials are configured
    if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
        throw new Exception("Google OAuth credentials not configured. Please check your .env file.");
    }
    
    if (empty($_GET['code'])) {
        throw new Exception("Authorization code not provided");
    }

    if (empty($_GET['state']) || !isset($_SESSION['oauth2state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
        header('Location: /login.php?error=invalid_state');
        exit;
    }

    $user = $auth->handleOAuthCallback('google', $_GET['code']);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['is_admin'] = $user['is_admin'];
    unset($_SESSION['oauth2state']);
    
    header('Location: /index.php');
    exit;
} catch (Exception $e) {
    error_log("Google OAuth Callback Error: " . $e->getMessage());
    header('Location: /login.php?error=' . urlencode($e->getMessage()));
    exit;
}

