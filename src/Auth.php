<?php

namespace BounceNG;

use League\OAuth2\Client\Provider\Google;
use TheNetworg\OAuth2\Client\Provider\Azure;

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getGoogleProvider() {
        return new Google([
            'clientId' => GOOGLE_CLIENT_ID,
            'clientSecret' => GOOGLE_CLIENT_SECRET,
            'redirectUri' => GOOGLE_REDIRECT_URI,
        ]);
    }

    public function getMicrosoftProvider() {
        return new Azure([
            'clientId' => MICROSOFT_CLIENT_ID,
            'clientSecret' => MICROSOFT_CLIENT_SECRET,
            'redirectUri' => MICROSOFT_REDIRECT_URI,
        ]);
    }

    public function handleOAuthCallback($provider, $code) {
        try {
            if ($provider === 'google') {
                $oauthProvider = $this->getGoogleProvider();
            } elseif ($provider === 'microsoft') {
                $oauthProvider = $this->getMicrosoftProvider();
            } else {
                throw new \Exception("Invalid provider");
            }

            $token = $oauthProvider->getAccessToken('authorization_code', [
                'code' => $code
            ]);

            $user = $oauthProvider->getResourceOwner($token);

            return $this->createOrUpdateUser($provider, $user);
        } catch (\Exception $e) {
            throw new \Exception("OAuth authentication failed: " . $e->getMessage());
        }
    }

    private function createOrUpdateUser($provider, $user) {
        $email = method_exists($user, 'getEmail') ? $user->getEmail() : ($user->getClaim('email') ?? '');
        $name = method_exists($user, 'getName') ? $user->getName() : ($user->getClaim('name') ?? '');
        if (empty($name)) {
            $firstName = method_exists($user, 'getFirstName') ? $user->getFirstName() : ($user->getClaim('given_name') ?? '');
            $lastName = method_exists($user, 'getLastName') ? $user->getLastName() : ($user->getClaim('family_name') ?? '');
            $name = trim($firstName . ' ' . $lastName);
        }
        $providerId = method_exists($user, 'getId') ? $user->getId() : ($user->getClaim('sub') ?? $user->getClaim('id') ?? '');

        // Check if user exists
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            WHERE provider = ? AND provider_id = ?
        ");
        $stmt->execute([$provider, $providerId]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Update last login
            $stmt = $this->db->prepare("
                UPDATE users 
                SET last_login = CURRENT_TIMESTAMP, email = ?, name = ?
                WHERE id = ?
            ");
            $stmt->execute([$email, $name, $existingUser['id']]);
            return $existingUser;
        }

        // Check if this is the first user (make them admin)
        // All subsequent users are read-only by default until approved by an admin
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        $isAdmin = $result['count'] == 0 ? 1 : 0; // Only first user gets admin automatically

        // Create new user
        $stmt = $this->db->prepare("
            INSERT INTO users (email, name, provider, provider_id, is_admin, last_login)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$email, $name, $provider, $providerId, $isAdmin]);

        $userId = $this->db->lastInsertId();

        return [
            'id' => $userId,
            'email' => $email,
            'name' => $name,
            'provider' => $provider,
            'provider_id' => $providerId,
            'is_admin' => $isAdmin,
            'is_active' => 1
        ];
    }

    public function isAdmin($userId) {
        $stmt = $this->db->prepare("SELECT is_admin FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && $user['is_admin'] == 1;
    }

    public function requireAuth() {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }

    public function requireAdmin() {
        $this->requireAuth();
        if (!$this->isAdmin($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }
}

