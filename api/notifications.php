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
                    SELECT nq.*, b.original_to, b.recipient_domain, b.smtp_code, sc.description as smtp_description, sc.recommendation as smtp_recommendation
                    FROM notifications_queue nq
                    JOIN bounces b ON nq.bounce_id = b.id
                    LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
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
                // Send specific notification IDs (selected notifications)
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

                // NotificationSender will get relay provider from notification/mailbox
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
            } elseif ($path === 'send-all') {
                // Send all pending notifications directly (synchronous) - restore original functionality
                header('Content-Type: application/json');
                
                $eventLogger = new \BounceNG\EventLogger();
                $userId = $_SESSION['user_id'] ?? null;
                
                // Set execution limits
                set_time_limit(1800);
                ini_set('max_execution_time', 1800);
                
                try {
                    // Get notification mode setting
                    $settingsStmt = $db->prepare("SELECT value FROM settings WHERE key = 'notification_mode'");
                    $settingsStmt->execute();
                    $settings = $settingsStmt->fetch();
                    $realTime = !$settings || $settings['value'] === 'realtime';
                    
                    if (!$realTime) {
                        echo json_encode(['success' => false, 'error' => 'Notification mode is set to queue - notifications must be sent manually']);
                        exit;
                    }
                    
                    // Get test mode settings
                    $testModeStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode'");
                    $testModeStmt->execute();
                    $testMode = $testModeStmt->fetch();
                    
                    $overrideEmailStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode_override_email'");
                    $overrideEmailStmt->execute();
                    $overrideEmail = $overrideEmailStmt->fetch();
                    
                    $isTestMode = $testMode && $testMode['value'] === '1';
                    $testEmail = $overrideEmail ? $overrideEmail['value'] : '';
                    
                    // Get pending notifications count
                    $countStmt = $db->query("SELECT COUNT(*) as count FROM notifications_queue WHERE status = 'pending'");
                    $count = $countStmt->fetch()['count'];
                    
                    if ($count == 0) {
                        echo json_encode(['success' => true, 'message' => 'No pending notifications to send', 'sent' => 0, 'failed' => 0]);
                        exit;
                    }
                    
                    $eventLogger->log('info', "Sending {$count} pending notification(s)", $userId);
                    
                    // Create notification sender
                    $sender = new \BounceNG\NotificationSender();
                    $sender->setTestMode($isTestMode, $testEmail);
                    
                    // Get all pending notifications
                    $stmt = $db->prepare("
                        SELECT id FROM notifications_queue 
                        WHERE status = 'pending' 
                        ORDER BY created_at ASC
                    ");
                    $stmt->execute();
                    $notifications = $stmt->fetchAll();
                    
                    $sentCount = 0;
                    $failedCount = 0;
                    
                    foreach ($notifications as $notification) {
                        try {
                            $success = $sender->sendNotification($notification['id']);
                            if ($success) {
                                $sentCount++;
                            } else {
                                $failedCount++;
                            }
                        } catch (Exception $e) {
                            $failedCount++;
                            $eventLogger->log('error', "Error sending notification ID {$notification['id']}: {$e->getMessage()}", $userId);
                        }
                    }
                    
                    $eventLogger->log('info', "Notification sending completed: {$sentCount} sent, {$failedCount} failed", $userId);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => "Sent {$sentCount} notification(s), {$failedCount} failed",
                        'sent' => $sentCount,
                        'failed' => $failedCount
                    ]);
                    
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    $eventLogger->log('error', "Fatal error during notification sending: {$errorMsg}", $userId);
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => $errorMsg]);
                }
            } elseif ($path === 'deduplicate') {
                // Deduplicate notifications - remove duplicates based on recipient_email, keep newest
                $auth->requireAdmin(); // Only admins can deduplicate
                
                $eventLogger = new \BounceNG\EventLogger();
                $userId = $_SESSION['user_id'] ?? null;
                
                try {
                    $db->beginTransaction();
                    
                    // Find all duplicate recipient_email addresses in pending notifications
                    // Get all recipient emails that appear more than once
                    $stmt = $db->query("
                        SELECT recipient_email, COUNT(*) as count
                        FROM notifications_queue
                        WHERE status = 'pending'
                        GROUP BY recipient_email
                        HAVING COUNT(*) > 1
                    ");
                    $duplicateEmails = $stmt->fetchAll();
                    
                    $totalMerged = 0;
                    $totalDeleted = 0;
                    
                    foreach ($duplicateEmails as $dup) {
                        $recipientEmail = $dup['recipient_email'];
                        $count = (int)$dup['count'];
                        
                        if ($count <= 1) {
                            continue;
                        }
                        
                        // Get all notifications for this recipient_email with their created_at dates
                        // Order by created_at DESC to get newest first
                        $stmt = $db->prepare("
                            SELECT id, created_at
                            FROM notifications_queue
                            WHERE recipient_email = ? AND status = 'pending'
                            ORDER BY created_at DESC
                        ");
                        $stmt->execute([$recipientEmail]);
                        $notifications = $stmt->fetchAll();
                        
                        if (count($notifications) <= 1) {
                            continue;
                        }
                        
                        // Keep the first one (newest created_at), delete the rest
                        $keepId = $notifications[0]['id'];
                        $deleteIds = array_slice(array_column($notifications, 'id'), 1);
                        
                        if (count($deleteIds) > 0) {
                            $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
                            $deleteStmt = $db->prepare("
                                DELETE FROM notifications_queue
                                WHERE id IN ({$deletePlaceholders})
                            ");
                            $deleteStmt->execute($deleteIds);
                            
                            $totalMerged += $count;
                            $totalDeleted += count($deleteIds);
                            
                            $eventLogger->log('info', "Deduplicated {$count} notifications for {$recipientEmail}: kept newest, deleted " . count($deleteIds) . " duplicate(s)", $userId);
                        }
                    }
                    
                    $db->commit();
                    
                    $eventLogger->log('info', "Deduplication complete: {$totalMerged} notifications merged, {$totalDeleted} duplicates removed", $userId);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Deduplication completed',
                        'merged' => $totalMerged,
                        'deleted' => $totalDeleted
                    ]);
                    
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
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

