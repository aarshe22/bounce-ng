<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;
use BounceNG\EventLogger;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$auth->requireAdmin();

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                $stmt = $db->query("SELECT id, email, name, provider, is_admin, is_active, created_at, last_login FROM users ORDER BY created_at DESC");
                $users = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $users]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'update' && isset($data['id'])) {
                $stmt = $db->prepare("
                    UPDATE users SET
                        is_admin = ?,
                        is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['is_admin'] ?? 0,
                    $data['is_active'] ?? 1,
                    $data['id']
                ]);
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "User updated: ID {$data['id']}", $_SESSION['user_id']);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            if ($path === 'delete' && isset($_GET['id'])) {
                // Don't allow deleting yourself
                if ($_GET['id'] == $_SESSION['user_id']) {
                    throw new Exception("Cannot delete your own account");
                }
                
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "User deleted: ID {$_GET['id']}", $_SESSION['user_id']);
                
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

