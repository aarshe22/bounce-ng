<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;
use BounceNG\NotificationSender;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$auth->requireAuth();

try {
    switch ($method) {
        case 'GET':
            if ($path === 'get') {
                $key = $_GET['key'] ?? null;
                if ($key) {
                    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
                    $stmt->execute([$key]);
                    $setting = $stmt->fetch();
                    echo json_encode(['success' => true, 'data' => $setting ? $setting['value'] : null]);
                } else {
                    // Get all settings
                    $stmt = $db->query("SELECT key, value FROM settings");
                    $settings = [];
                    while ($row = $stmt->fetch()) {
                        $settings[$row['key']] = $row['value'];
                    }
                    echo json_encode(['success' => true, 'data' => $settings]);
                }
            } elseif ($path === 'template') {
                $stmt = $db->query("SELECT * FROM notification_template ORDER BY id DESC LIMIT 1");
                $template = $stmt->fetch();
                echo json_encode(['success' => true, 'data' => $template]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'POST':
        case 'PUT':
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            
            if ($path === 'send-test') {
                $email = trim($data['email'] ?? '');
                if (empty($email)) {
                    throw new Exception("Email address is required");
                }
                $sender = new NotificationSender();
                $sender->sendTestEmail($email);
                echo json_encode(['success' => true, 'message' => 'Test email sent']);
            } elseif ($path === 'set') {
                $key = $data['key'] ?? null;
                $value = $data['value'] ?? null;
                
                if (!$key) {
                    throw new Exception("Key is required");
                }

                // Check if setting exists
                $checkStmt = $db->prepare("SELECT key FROM settings WHERE key = ?");
                $checkStmt->execute([$key]);
                if ($checkStmt->fetch()) {
                    $stmt = $db->prepare("UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = ?");
                    $stmt->execute([$value, $key]);
                } else {
                    $stmt = $db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                    $stmt->execute([$key, $value]);
                }
                
                echo json_encode(['success' => true]);
            } elseif ($path === 'template') {
                $subject = $data['subject'] ?? '';
                $body = $data['body'] ?? '';
                
                if (!$subject || !$body) {
                    throw new Exception("Subject and body are required");
                }

                $stmt = $db->prepare("
                    INSERT INTO notification_template (subject, body, updated_at)
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$subject, $body]);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

