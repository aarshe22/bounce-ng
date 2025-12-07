<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;

$auth = new Auth();
$provider = $auth->getGoogleProvider();

if (!isset($_GET['code'])) {
    $authUrl = $provider->getAuthorizationUrl();
    $_SESSION['oauth2state'] = $provider->getState();
    header('Location: ' . $authUrl);
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
    
    header('Location: /');
} catch (Exception $e) {
    header('Location: /login.php?error=' . urlencode($e->getMessage()));
}

