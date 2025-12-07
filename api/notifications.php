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
            if ($path === 'queue') {
                $status = $_GET['status'] ?? 'pending';
                $stmt = $db->prepare("
                    SELECT nq.*, b.original_to, b.recipient_domain, b.smtp_code
                    FROM notifications_queue nq
                    JOIN bounces b ON nq.bounce_id = b.id
                    WHERE nq.status = ?
                    ORDER BY nq.created_at ASC
                ");
                $stmt->execute([$status]);
                $notifications = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $notifications]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if ($path === 'send' && isset($data['ids'])) {
                $ids = $data['ids'];
                if (!is_array($ids)) {
                    throw new Exception("Invalid IDs format");
                }

                // Get settings
                $testModeStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode'");
                $testModeStmt->execute();
                $testMode = $testModeStmt->fetch();
                
                $overrideEmailStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode_override_email'");
                $overrideEmailStmt->execute();
                $overrideEmail = $overrideEmailStmt->fetch();

                $sender = new NotificationSender();
                $sender->setTestMode(
                    $testMode && $testMode['value'] === '1',
                    $overrideEmail ? $overrideEmail['value'] : ''
                );

                $results = [];
                foreach ($ids as $id) {
                    try {
                        $success = $sender->sendNotification($id);
                        $results[] = ['id' => $id, 'success' => $success];
                    } catch (Exception $e) {
                        $results[] = ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
                    }
                }

                echo json_encode(['success' => true, 'data' => $results]);
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

