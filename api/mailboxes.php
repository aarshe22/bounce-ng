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
                $stmt = $db->prepare("
                    SELECT m.*, rp.name as relay_provider_name 
                    FROM mailboxes m 
                    LEFT JOIN relay_providers rp ON m.relay_provider_id = rp.id
                    ORDER BY m.created_at DESC
                ");
                $stmt->execute();
                $mailboxes = $stmt->fetchAll();
                // Remove passwords from response
                foreach ($mailboxes as &$mailbox) {
                    unset($mailbox['imap_password']);
                }
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
                        folder_problem, folder_skipped, relay_provider_id, is_enabled
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                    $data['relay_provider_id'] ?? null,
                    $data['is_enabled'] ?? 1
                ]);
                
                $mailboxId = $db->lastInsertId();
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Mailbox created: {$data['name']}", $_SESSION['user_id'], $mailboxId);
                
                echo json_encode(['success' => true, 'id' => $mailboxId]);
            } elseif ($path === 'retroactive-queue') {
                // First, check how many bounces exist and how many have CC addresses
                $checkStmt = $db->query("
                    SELECT 
                        COUNT(*) as total_bounces,
                        COUNT(CASE WHEN original_cc IS NOT NULL AND original_cc != '' THEN 1 END) as bounces_with_cc,
                        COUNT(CASE WHEN original_cc IS NOT NULL AND original_cc != '' AND original_cc != 'null' THEN 1 END) as bounces_with_valid_cc
                    FROM bounces
                ");
                $checkResult = $checkStmt->fetch();
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Bounce stats: Total: {$checkResult['total_bounces']}, With CC: {$checkResult['bounces_with_cc']}, Valid CC: {$checkResult['bounces_with_valid_cc']}", $_SESSION['user_id'] ?? null);
                
                // Retroactively queue notifications for ALL existing bounces that have CC addresses but no notifications
                // Use a subquery to find bounces that don't have any notifications yet
                $stmt = $db->query("
                    SELECT b.id, b.original_cc, b.mailbox_id, b.original_to
                    FROM bounces b
                    WHERE b.original_cc IS NOT NULL 
                    AND b.original_cc != ''
                    AND b.original_cc != 'null'
                    AND NOT EXISTS (
                        SELECT 1 FROM notifications_queue nq 
                        WHERE nq.bounce_id = b.id
                    )
                ");
                $bounces = $stmt->fetchAll();
                
                $eventLogger->log('info', "Found " . count($bounces) . " bounces with CC addresses that need notifications", $_SESSION['user_id'] ?? null);
                
                $queuedCount = 0;
                
                foreach ($bounces as $bounce) {
                    $ccList = trim($bounce['original_cc'] ?? '');
                    if (empty($ccList) || $ccList === 'null') {
                        continue;
                    }
                    
                    $eventLogger->log('debug', "Processing bounce ID {$bounce['id']} with CC: {$ccList}", $_SESSION['user_id'] ?? null, $bounce['mailbox_id']);
                    
                    // Parse CC string - extract all email addresses
                    $emails = [];
                    
                    // Method 1: Extract emails using regex
                    if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/i', $ccList, $matches)) {
                        $emails = array_unique(array_map('strtolower', $matches[0]));
                    }
                    
                    // Method 2: If regex didn't find anything, try splitting by comma
                    if (empty($emails)) {
                        $parts = preg_split('/[,\s]+/', $ccList);
                        foreach ($parts as $part) {
                            $part = trim($part);
                            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                                $emails[] = strtolower($part);
                            }
                        }
                        $emails = array_unique($emails);
                    }
                    
                    $eventLogger->log('debug', "Extracted " . count($emails) . " email addresses from CC list for bounce ID {$bounce['id']}", $_SESSION['user_id'] ?? null, $bounce['mailbox_id']);
                    
                    // Queue each email
                    $queueStmt = $db->prepare("
                        INSERT OR IGNORE INTO notifications_queue (bounce_id, recipient_email, status)
                        VALUES (?, ?, 'pending')
                    ");
                    
                    foreach ($emails as $email) {
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            try {
                                $queueStmt->execute([$bounce['id'], $email]);
                                if ($queueStmt->rowCount() > 0) {
                                    $queuedCount++;
                                    $eventLogger->log('debug', "Queued notification for {$email} (bounce ID: {$bounce['id']})", $_SESSION['user_id'] ?? null, $bounce['mailbox_id']);
                                }
                            } catch (Exception $e) {
                                $eventLogger->log('warning', "Failed to queue notification for {$email}: {$e->getMessage()}", $_SESSION['user_id'] ?? null, $bounce['mailbox_id']);
                            }
                        }
                    }
                }
                
                $eventLogger->log('info', "Retroactively queued {$queuedCount} notifications from " . count($bounces) . " existing bounces", $_SESSION['user_id'] ?? null);
                
                echo json_encode(['success' => true, 'queued' => $queuedCount, 'bounces_processed' => count($bounces)]);
            } elseif ($path === 'reset-database') {
                // Reset database - clear all data except users, relays, and mailboxes
                $auth->requireAdmin();
                try {
                    $db->beginTransaction();
                    
                    // Clear all bounces
                    $db->exec("DELETE FROM bounces");
                    
                    // Clear all notifications
                    $db->exec("DELETE FROM notifications_queue");
                    
                    // Clear all recipient domains
                    $db->exec("DELETE FROM recipient_domains");
                    
                    // Clear all events log
                    $db->exec("DELETE FROM events_log");
                    
                    // Clear SMTP codes (optional - you might want to keep these)
                    // $db->exec("DELETE FROM smtp_codes");
                    
                    // Reset mailbox last_processed timestamps
                    $db->exec("UPDATE mailboxes SET last_processed = NULL");
                    
                    $db->commit();
                    
                    $eventLogger = new EventLogger();
                    $eventLogger->log('warning', "Database reset completed - all bounces, notifications, domains, and events cleared", $_SESSION['user_id'] ?? null);
                    
                    echo json_encode(['success' => true, 'message' => 'Database reset successfully']);
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
            } elseif ($path === 'process' && isset($data['mailbox_id'])) {
                // Set longer execution time and ignore user abort for long-running processing
                set_time_limit(300); // 5 minutes
                ignore_user_abort(true);
                
                // Send headers to prevent timeout
                header('Content-Type: application/json');
                header('Connection: keep-alive');
                
                // Flush output immediately so client doesn't timeout
                if (function_exists('fastcgi_finish_request')) {
                    // For FastCGI, send response immediately and continue processing
                    echo json_encode(['success' => true, 'status' => 'processing', 'message' => 'Processing started']);
                    fastcgi_finish_request();
                } else {
                    // For non-FastCGI, send chunked response
                    header('Transfer-Encoding: chunked');
                    ob_start();
                    echo json_encode(['success' => true, 'status' => 'processing', 'message' => 'Processing started']);
                    ob_flush();
                    flush();
                }
                
                try {
                    $monitor = new MailboxMonitor($data['mailbox_id']);
                    // Don't call connect() here - processInbox() handles its own connection
                    $eventLogger = new EventLogger();
                    $eventLogger->log('info', "Starting mailbox processing for mailbox ID: {$data['mailbox_id']}", $_SESSION['user_id'] ?? null, $data['mailbox_id']);
                    $result = $monitor->processInbox();
                    $monitor->disconnect();
                    $eventLogger->log('info', "Mailbox processing completed successfully", $_SESSION['user_id'] ?? null, $data['mailbox_id']);
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    error_log("Error processing mailbox: " . $errorMsg);
                    $eventLogger = new EventLogger();
                    $eventLogger->log('error', "Error processing mailbox: {$errorMsg}", $_SESSION['user_id'] ?? null, $data['mailbox_id'] ?? null);
                    http_response_code(500);
                    // Error already logged, just exit
                    if (!function_exists('fastcgi_finish_request')) {
                        echo json_encode(['success' => false, 'error' => $errorMsg]);
                    }
                    exit;
                }
                
                // Return result if not using fastcgi_finish_request
                if (!function_exists('fastcgi_finish_request')) {
                    // Send notifications if real-time mode
                    $settingsStmt = $db->prepare("SELECT value FROM settings WHERE key = 'notification_mode'");
                    $settingsStmt->execute();
                    $settings = $settingsStmt->fetch();
                    $realTime = !$settings || $settings['value'] === 'realtime';
                    
                    if ($realTime) {
                        // Get relay provider from mailbox
                        $mailboxStmt = $db->prepare("SELECT relay_provider_id FROM mailboxes WHERE id = ?");
                        $mailboxStmt->execute([$data['mailbox_id']]);
                        $mailbox = $mailboxStmt->fetch();
                        $relayProviderId = $mailbox['relay_provider_id'] ?? null;
                        
                        $notificationSender = new \BounceNG\NotificationSender($relayProviderId);
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
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'update' && isset($data['id'])) {
                $updatePassword = isset($data['imap_password']) && !empty($data['imap_password']);
                $updateRelay = isset($data['relay_provider_id']);
                
                $sql = "UPDATE mailboxes SET
                    name = ?, email = ?, imap_server = ?, imap_port = ?,
                    imap_protocol = ?, imap_username = ?,
                    folder_inbox = ?, folder_processed = ?, folder_problem = ?,
                    folder_skipped = ?, is_enabled = ?, updated_at = CURRENT_TIMESTAMP";
                
                if ($updatePassword) {
                    $sql .= ", imap_password = ?";
                }
                if ($updateRelay) {
                    $sql .= ", relay_provider_id = ?";
                }
                
                $sql .= " WHERE id = ?";
                
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
                
                if ($updatePassword) {
                    $params[] = $data['imap_password'];
                }
                if ($updateRelay) {
                    $params[] = $data['relay_provider_id'] ?: null;
                }
                $params[] = $data['id'];
                
                $stmt = $db->prepare($sql);
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

