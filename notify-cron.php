#!/usr/bin/env php
<?php
/**
 * BounceNG Notification Cron Script
 * 
 * This script processes mailboxes, queues notifications, and sends pending notifications.
 * Can be run from CLI (cron) or called from web interface.
 * 
 * Usage:
 *   php notify-cron.php                    # Process and send (default)
 *   php notify-cron.php --process-only      # Only process mailboxes and queue notifications
 *   php notify-cron.php --send-only        # Only send pending notifications
 *   php notify-cron.php --dedupe           # Deduplicate notifications (can be combined with other flags)
 * 
 * Exit codes:
 *   0 = Success
 *   1 = General error
 *   2 = Processing error
 *   3 = Sending error
 *   4 = Deduplication error
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use BounceNG\Database;
use BounceNG\MailboxMonitor;
use BounceNG\NotificationSender;
use BounceNG\EventLogger;

// Determine if running from CLI or web
$isCli = php_sapi_name() === 'cli';

// Log file path - use data directory (should be writable)
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$logFile = $dataDir . '/notify-cron.log';

// Function to log to both file and event log
function cronLog($level, $message, $eventLogger = null, $userId = null, $mailboxId = null) {
    global $logFile, $isCli;
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] [CRON] {$message}\n";
    
    // Always log to file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Log to event log if available
    if ($eventLogger) {
        $eventLogger->log($level, "[CRON] {$message}", $userId, $mailboxId);
    }
    
    // Output to console if CLI
    if ($isCli) {
        echo $logMessage;
    }
}

// Parse command line arguments
$processOnly = false;
$sendOnly = false;
$dedupe = false;
$webCall = false;
$userId = null;

if ($isCli) {
    // CLI mode - parse arguments
    $args = array_slice($argv, 1);
    foreach ($args as $arg) {
        if ($arg === '--process-only') {
            $processOnly = true;
        } elseif ($arg === '--send-only') {
            $sendOnly = true;
        } elseif ($arg === '--dedupe') {
            $dedupe = true;
        }
    }
} else {
    // Web mode - check authentication and parse request
    session_start();
    require_once __DIR__ . '/src/Auth.php';
    $auth = new \BounceNG\Auth();
    
    if (!$auth->isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit(1);
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    
    // Parse request data from query string or POST
    $processOnly = isset($_GET['process_only']) || isset($_POST['process_only']);
    $sendOnly = isset($_GET['send_only']) || isset($_POST['send_only']);
    
    $webCall = true;
    
    // For web calls, run asynchronously
    // Send response immediately
    header('Content-Type: application/json');
    header('Connection: close');
    
    $response = json_encode(['success' => true, 'status' => 'processing', 'message' => 'Cron script started in background']);
    header('Content-Length: ' . strlen($response));
    echo $response;
    
    // Flush output
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    
    // Finish request if FastCGI
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    // Set execution limits
    set_time_limit(1800);
    ini_set('max_execution_time', 1800);
    ignore_user_abort(true);
}

// Initialize
$db = Database::getInstance();
$eventLogger = new EventLogger();
$exitCode = 0;

// Log script start with detailed info - use 'info' level so it shows in event log
cronLog('info', "=== CRON SCRIPT STARTED ===", $eventLogger, $userId);
cronLog('info', "CLI mode: " . ($isCli ? 'YES' : 'NO'), $eventLogger, $userId);
cronLog('info', "Process only: " . ($processOnly ? 'YES' : 'NO'), $eventLogger, $userId);
cronLog('info', "Send only: " . ($sendOnly ? 'YES' : 'NO'), $eventLogger, $userId);
cronLog('info', "Dedupe: " . ($dedupe ? 'YES' : 'NO'), $eventLogger, $userId);
cronLog('info', "Web call: " . ($webCall ? 'YES' : 'NO'), $eventLogger, $userId);
cronLog('info', "User ID: " . ($userId ?? 'NULL'), $eventLogger, $userId);
cronLog('info', "PHP version: " . PHP_VERSION, $eventLogger, $userId);
cronLog('info', "Working directory: " . getcwd(), $eventLogger, $userId);
cronLog('info', "Script path: " . __FILE__, $eventLogger, $userId);
cronLog('info', "Process ID (PID): " . getmypid(), $eventLogger, $userId);

try {
    // Get test mode settings
    $testModeStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode'");
    $testModeStmt->execute();
    $testMode = $testModeStmt->fetch();
    
    $overrideEmailStmt = $db->prepare("SELECT value FROM settings WHERE key = 'test_mode_override_email'");
    $overrideEmailStmt->execute();
    $overrideEmail = $overrideEmailStmt->fetch();
    
    $isTestMode = $testMode && $testMode['value'] === '1';
    $testEmail = $overrideEmail ? $overrideEmail['value'] : '';
    
    if ($isTestMode) {
        cronLog('info', "Test mode enabled - notifications will be sent to: {$testEmail}", $eventLogger, $userId);
    }
    
    // Step 1: Process mailboxes and queue notifications (unless send-only)
    if (!$sendOnly) {
        cronLog('info', "Starting mailbox processing phase...", $eventLogger, $userId);
        
        try {
            // Get all enabled mailboxes
            $stmt = $db->query("SELECT id FROM mailboxes WHERE is_enabled = 1");
            $mailboxes = $stmt->fetchAll();
            
            if (empty($mailboxes)) {
                cronLog('info', "No enabled mailboxes found", $eventLogger, $userId);
            } else {
                cronLog('info', "Found " . count($mailboxes) . " enabled mailbox(es) to process", $eventLogger, $userId);
                
                foreach ($mailboxes as $mailbox) {
                    $mailboxId = $mailbox['id'];
                    try {
                        cronLog('info', "Processing mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        
                        $monitor = new MailboxMonitor($mailboxId);
                        $result = $monitor->processInbox();
                        $monitor->disconnect();
                        
                        cronLog('info', "Mailbox {$mailboxId} processed: {$result['processed']} processed, {$result['skipped']} skipped, {$result['problems']} problems", 
                            $eventLogger, $userId, $mailboxId);
                    } catch (Exception $e) {
                        $errorMsg = $e->getMessage();
                        cronLog('error', "Error processing mailbox {$mailboxId}: {$errorMsg}", $eventLogger, $userId, $mailboxId);
                        $exitCode = 2; // Processing error
                        // Continue with other mailboxes
                    }
                }
            }
            
            cronLog('info', "Mailbox processing phase completed", $eventLogger, $userId);
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            cronLog('error', "Fatal error during mailbox processing: {$errorMsg}", $eventLogger, $userId);
            $exitCode = 2;
        }
    }
    
    // Step 2: Send pending notifications (unless process-only)
    if (!$processOnly) {
        cronLog('info', "Starting notification sending phase...", $eventLogger, $userId);
        
        try {
            // Get notification mode setting
            $settingsStmt = $db->prepare("SELECT value FROM settings WHERE key = 'notification_mode'");
            $settingsStmt->execute();
            $settings = $settingsStmt->fetch();
            $realTime = !$settings || $settings['value'] === 'realtime';
            
            if (!$realTime) {
                cronLog('info', "Notification mode is 'queue' - notifications will be sent manually", $eventLogger, $userId);
            } else {
                // Get pending notifications count
                $countStmt = $db->query("SELECT COUNT(*) as count FROM notifications_queue WHERE status = 'pending'");
                $count = $countStmt->fetch()['count'];
                
                if ($count == 0) {
                    cronLog('info', "No pending notifications to send", $eventLogger, $userId);
                } else {
                    cronLog('info', "Found {$count} pending notification(s) to send", $eventLogger, $userId);
                    
                    // Create notification sender
                    $sender = new NotificationSender();
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
                            cronLog('error', "Error sending notification ID {$notification['id']}: {$e->getMessage()}", 
                                $eventLogger, $userId);
                        }
                    }
                    
                    cronLog('info', "Notification sending completed: {$sentCount} sent, {$failedCount} failed", 
                        $eventLogger, $userId);
                    
                    if ($failedCount > 0) {
                        $exitCode = 3; // Sending error
                    }
                }
            }
            
            cronLog('info', "Notification sending phase completed", $eventLogger, $userId);
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            cronLog('error', "Fatal error during notification sending: {$errorMsg}", $eventLogger, $userId);
            $exitCode = 3;
        }
    }
    
    // Step 3: Deduplicate notifications (if --dedupe flag is set)
    if ($dedupe) {
        cronLog('info', "Starting notification deduplication phase...", $eventLogger, $userId);
        
        try {
            $db->beginTransaction();
            
            // Find all duplicate recipient_email + original_to pairs in pending notifications
            // Group by both recipient_email and original_to to find true duplicates
            $stmt = $db->query("
                SELECT nq.recipient_email, b.original_to, COUNT(*) as count
                FROM notifications_queue nq
                JOIN bounces b ON nq.bounce_id = b.id
                WHERE nq.status = 'pending'
                GROUP BY nq.recipient_email, b.original_to
                HAVING COUNT(*) > 1
            ");
            $duplicatePairs = $stmt->fetchAll();
            
            $totalMerged = 0;
            $totalDeleted = 0;
            
            if (empty($duplicatePairs)) {
                cronLog('info', "No duplicate notifications found", $eventLogger, $userId);
            } else {
                cronLog('info', "Found " . count($duplicatePairs) . " duplicate CC+TO pair(s) to process", $eventLogger, $userId);
                
                foreach ($duplicatePairs as $dup) {
                    $recipientEmail = $dup['recipient_email'];
                    $originalTo = $dup['original_to'];
                    $count = (int)$dup['count'];
                    
                    if ($count <= 1) {
                        continue;
                    }
                    
                    // Get all notifications for this recipient_email + original_to pair with their created_at dates
                    // Order by created_at DESC to get newest first
                    $stmt = $db->prepare("
                        SELECT nq.id, nq.created_at
                        FROM notifications_queue nq
                        JOIN bounces b ON nq.bounce_id = b.id
                        WHERE nq.recipient_email = ? AND b.original_to = ? AND nq.status = 'pending'
                        ORDER BY nq.created_at DESC
                    ");
                    $stmt->execute([$recipientEmail, $originalTo]);
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
                        
                        cronLog('info', "Deduplicated {$count} notifications for CC:{$recipientEmail} + TO:{$originalTo}: kept newest, deleted " . count($deleteIds) . " duplicate(s)", 
                            $eventLogger, $userId);
                    }
                }
                
                $db->commit();
                
                cronLog('info', "Deduplication completed: {$totalMerged} notifications merged, {$totalDeleted} duplicates removed", $eventLogger, $userId);
            }
            
            cronLog('info', "Notification deduplication phase completed", $eventLogger, $userId);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errorMsg = $e->getMessage();
            cronLog('error', "Fatal error during notification deduplication: {$errorMsg}", $eventLogger, $userId);
            $exitCode = 4; // Deduplication error
        }
    }
    
    // Summary
    if ($exitCode === 0) {
        cronLog('info', "Cron script completed successfully", $eventLogger, $userId);
    } else {
        cronLog('warning', "Cron script completed with errors (exit code: {$exitCode})", $eventLogger, $userId);
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    cronLog('error', "Fatal error in cron script: {$errorMsg}", $eventLogger, $userId);
    $exitCode = 1;
}

// Exit with appropriate code
exit($exitCode);

