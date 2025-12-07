<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bounce Monitor - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login-card {
            max-width: 400px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="card shadow-lg">
            <div class="card-body p-5">
                <h2 class="card-title text-center mb-4">Bounce Monitor</h2>
                <p class="text-center text-muted mb-4">Sign in with your account</p>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_GET['error']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <div class="d-grid gap-2">
                    <?php if (!empty(GOOGLE_CLIENT_ID) && !empty(GOOGLE_CLIENT_SECRET)): ?>
                    <a href="/auth/google.php" class="btn btn-danger">
                        <svg width="18" height="18" viewBox="0 0 18 18" class="me-2">
                            <path fill="#fff" d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.428h2.908c1.702-1.567 2.684-3.874 2.684-6.785z"/>
                            <path fill="#fff" d="M9 18c2.43 0 4.467-.806 5.965-2.18l-2.908-2.428c-.806.54-1.837.86-3.057.86-2.35 0-4.34-1.587-5.053-3.72H.957v2.503C2.438 15.983 5.482 18 9 18z"/>
                            <path fill="#fff" d="M3.952 10.432c-.18-.54-.282-1.117-.282-1.705s.102-1.165.282-1.705V4.519H.957C.348 5.539 0 6.72 0 8s.348 2.461.957 3.481l3.005-2.049z"/>
                            <path fill="#fff" d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.519L3.952 7.068C4.665 4.933 6.655 3.346 9 3.346z"/>
                        </svg>
                        Sign in with Google
                    </a>
                    <?php endif; ?>
                    <?php if (!empty(MICROSOFT_CLIENT_ID) && !empty(MICROSOFT_CLIENT_SECRET)): ?>
                    <a href="/auth/microsoft.php" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 23 23" class="me-2">
                            <path fill="#fff" d="M0 0h11.377v11.372H0z"/>
                            <path fill="#fff" d="M11.863 0H23v11.372H11.863z"/>
                            <path fill="#fff" d="M0 11.628h11.377V23H0z"/>
                            <path fill="#fff" d="M11.863 11.628H23V23H11.863z"/>
                        </svg>
                        Sign in with Microsoft
                    </a>
                    <?php endif; ?>
                    <?php if (empty(GOOGLE_CLIENT_ID) && empty(MICROSOFT_CLIENT_ID)): ?>
                    <div class="alert alert-warning">
                        <strong>No OAuth providers configured.</strong><br>
                        Please configure at least one OAuth provider in your .env file.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

