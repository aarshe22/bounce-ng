<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;
use BounceNG\MailboxMonitor;
use BounceNG\EventLogger;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$auth->requireAuth();

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                $stmt = $db->prepare("SELECT * FROM mailboxes ORDER BY created_at DESC");
                $stmt->execute();
                $mailboxes = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $mailboxes]);
            } elseif ($path === 'get' && isset($_GET['id'])) {
                $stmt = $db->prepare("SELECT * FROM mailboxes WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $mailbox = $stmt->fetch();
                if ($mailbox) {
                    // Don't return password
                    unset($mailbox['imap_password']);
                    echo json_encode(['success' => true, 'data' => $mailbox]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
                }
            } elseif ($path === 'folders' && isset($_GET['id'])) {
                $monitor = new MailboxMonitor($_GET['id']);
                $monitor->connect();
                $folders = $monitor->getFolders();
                $monitor->disconnect();
                echo json_encode(['success' => true, 'data' => $folders]);
            } elseif ($path === 'test' && isset($_GET['id'])) {
                $monitor = new MailboxMonitor($_GET['id']);
                try {
                    $monitor->connect();
                    $monitor->disconnect();
                    echo json_encode(['success' => true, 'message' => 'Connection successful']);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'create') {
                $auth->requireAdmin();
                
                $stmt = $db->prepare("
                    INSERT INTO mailboxes (
                        name, email, imap_server, imap_port, imap_protocol,
                        imap_username, imap_password, folder_inbox, folder_processed,
                        folder_problem, folder_skipped, is_enabled
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $data['name'],
                    $data['email'],
                    $data['imap_server'],
                    $data['imap_port'],
                    $data['imap_protocol'],
                    $data['imap_username'],
                    $data['imap_password'],
                    $data['folder_inbox'] ?? 'INBOX',
                    $data['folder_processed'] ?? 'Processed',
                    $data['folder_problem'] ?? 'Problem',
                    $data['folder_skipped'] ?? 'Skipped',
                    $data['is_enabled'] ?? 1
                ]);
                
                $mailboxId = $db->lastInsertId();
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Mailbox created: {$data['name']}", $_SESSION['user_id'], $mailboxId);
                
                echo json_encode(['success' => true, 'id' => $mailboxId]);
            } elseif ($path === 'process' && isset($data['mailbox_id'])) {
                $monitor = new MailboxMonitor($data['mailbox_id']);
                $monitor->connect();
                $result = $monitor->processInbox();
                $monitor->disconnect();
                
                // Send notifications if real-time mode
                $settingsStmt = $db->prepare("SELECT value FROM settings WHERE key = 'notification_mode'");
                $settingsStmt->execute();
                $settings = $settingsStmt->fetch();
                $realTime = !$settings || $settings['value'] === 'realtime';
                
                if ($realTime) {
                    $notificationSender = new \BounceNG\NotificationSender();
                    $testModeStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode'");
                    $testModeStmt->execute();
                    $testMode = $testModeStmt->fetch();
                    $overrideEmailStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode_override_email'");
                    $overrideEmailStmt->execute();
                    $overrideEmail = $overrideEmailStmt->fetch();
                    
                    $notificationSender->setTestMode(
                        $testMode && $testMode['value'] === '1',
                        $overrideEmail ? $overrideEmail['value'] : ''
                    );
                    $notificationSender->sendPendingNotifications(true);
                }
                
                echo json_encode(['success' => true, 'data' => $result]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'update' && isset($data['id'])) {
                $stmt = $db->prepare("
                    UPDATE mailboxes SET
                        name = ?, email = ?, imap_server = ?, imap_port = ?,
                        imap_protocol = ?, imap_username = ?,
                        folder_inbox = ?, folder_processed = ?, folder_problem = ?,
                        folder_skipped = ?, is_enabled = ?, updated_at = CURRENT_TIMESTAMP
                        " . (isset($data['imap_password']) ? ", imap_password = ?" : "") . "
                    WHERE id = ?
                ");
                
                $params = [
                    $data['name'],
                    $data['email'],
                    $data['imap_server'],
                    $data['imap_port'],
                    $data['imap_protocol'],
                    $data['imap_username'],
                    $data['folder_inbox'],
                    $data['folder_processed'],
                    $data['folder_problem'],
                    $data['folder_skipped'],
                    $data['is_enabled'] ?? 1
                ];
                
                if (isset($data['imap_password'])) {
                    $params[] = $data['imap_password'];
                }
                $params[] = $data['id'];
                
                $stmt->execute($params);
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Mailbox updated: {$data['name']}", $_SESSION['user_id'], $data['id']);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            $auth->requireAdmin();
            
            if ($path === 'delete' && isset($_GET['id'])) {
                $stmt = $db->prepare("DELETE FROM mailboxes WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Mailbox deleted: ID {$_GET['id']}", $_SESSION['user_id']);
                
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

