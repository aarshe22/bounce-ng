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

// Change to script directory to ensure relative paths work
$scriptDir = dirname(__FILE__);
chdir($scriptDir);

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
$logFile = null;
$logFileWritable = false;

// Ensure data directory exists and is writable
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

if (is_dir($dataDir)) {
    $logFile = $dataDir . '/notify-cron.log';
    // Try to create/write to log file to verify permissions
    $testWrite = @file_put_contents($logFile, '', FILE_APPEND | LOCK_EX);
    if ($testWrite !== false) {
        $logFileWritable = true;
    }
}

// Function to log to both file and event log
function cronLog($level, $message, $eventLogger = null, $userId = null, $mailboxId = null) {
    global $logFile, $logFileWritable, $isCli;
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] [CRON] {$message}\n";
    
    // Always try to log to file if writable
    if ($logFileWritable && $logFile) {
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    // Log to event log if available
    if ($eventLogger) {
        try {
            $eventLogger->log($level, "[CRON] {$message}", $userId, $mailboxId);
        } catch (Exception $e) {
            // If event log fails, at least try to write to file
            if ($logFileWritable && $logFile) {
                @file_put_contents($logFile, "[{$timestamp}] [error] [CRON] Failed to write to event log: {$e->getMessage()}\n", FILE_APPEND | LOCK_EX);
            }
        }
    }
    
    // Output to console if CLI
    if ($isCli) {
        echo $logMessage;
    }
}

// Function to log errors with variable dumps
function cronLogError($message, $exception = null, $eventLogger = null, $userId = null, $mailboxId = null, $context = []) {
    global $logFile, $logFileWritable, $isCli;
    
    $timestamp = date('Y-m-d H:i:s');
    $errorDetails = [];
    
    if ($exception) {
        $errorDetails[] = "Exception: " . get_class($exception);
        $errorDetails[] = "Message: " . $exception->getMessage();
        $errorDetails[] = "File: " . $exception->getFile();
        $errorDetails[] = "Line: " . $exception->getLine();
        $errorDetails[] = "Trace: " . $exception->getTraceAsString();
    }
    
    if (!empty($context)) {
        $errorDetails[] = "Context variables:";
        foreach ($context as $key => $value) {
            $errorDetails[] = "  {$key}: " . (is_scalar($value) ? $value : print_r($value, true));
        }
    }
    
    $fullMessage = $message;
    if (!empty($errorDetails)) {
        $fullMessage .= "\n" . implode("\n", $errorDetails);
    }
    
    $logMessage = "[{$timestamp}] [error] [CRON] {$fullMessage}\n";
    
    // Always try to log to file if writable
    if ($logFileWritable && $logFile) {
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    // Log to event log if available
    if ($eventLogger) {
        try {
            $eventLogger->log('error', "[CRON] {$message}" . ($exception ? ": {$exception->getMessage()}" : ""), $userId, $mailboxId);
        } catch (Exception $e) {
            // If event log fails, at least try to write to file
            if ($logFileWritable && $logFile) {
                @file_put_contents($logFile, "[{$timestamp}] [error] [CRON] Failed to write to event log: {$e->getMessage()}\n", FILE_APPEND | LOCK_EX);
            }
        }
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
    
    // Check if user is authenticated by checking session
    if (!isset($_SESSION['user_id'])) {
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
$db = null;
$eventLogger = null;
$exitCode = 0;

// Log script start with detailed info - use 'info' level so it shows in event log
cronLog('info', "=== CRON SCRIPT STARTED ===", null, $userId);
cronLog('info', "CLI mode: " . ($isCli ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Process only: " . ($processOnly ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Send only: " . ($sendOnly ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Dedupe: " . ($dedupe ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Web call: " . ($webCall ? 'YES' : 'NO'), null, $userId);
cronLog('info', "User ID: " . ($userId ?? 'NULL'), null, $userId);
cronLog('info', "PHP version: " . PHP_VERSION, null, $userId);
cronLog('info', "Working directory: " . getcwd(), null, $userId);
cronLog('info', "Script path: " . __FILE__, null, $userId);
cronLog('info', "Script directory: " . __DIR__, null, $userId);
cronLog('info', "Process ID (PID): " . getmypid(), null, $userId);
cronLog('info', "Current user: " . (function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown'), null, $userId);
cronLog('info', "Log file: " . ($logFile ?? 'NOT SET'), null, $userId);
cronLog('info', "Log file writable: " . ($logFileWritable ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Data directory: " . $dataDir, null, $userId);
cronLog('info', "Data directory exists: " . (is_dir($dataDir) ? 'YES' : 'NO'), null, $userId);
cronLog('info', "Data directory writable: " . (is_writable($dataDir) ? 'YES' : 'NO'), null, $userId);

// Initialize database and event logger
try {
    cronLog('info', "Initializing database connection...", null, $userId);
    $db = Database::getInstance();
    cronLog('info', "Database connection established", null, $userId);
    
    // Test database connectivity
    try {
        $testStmt = $db->query("SELECT 1");
        $testStmt->fetch();
        cronLog('info', "Database connectivity test passed", null, $userId);
    } catch (Exception $e) {
        cronLogError("Database connectivity test failed", $e, null, $userId, null, [
            'db_path' => defined('DB_PATH') ? DB_PATH : 'NOT DEFINED'
        ]);
        throw $e;
    }
    
    cronLog('info', "Initializing event logger...", null, $userId);
    $eventLogger = new EventLogger();
    cronLog('info', "Event logger initialized", $eventLogger, $userId);
} catch (Exception $e) {
    cronLogError("Failed to initialize database or event logger", $e, null, $userId, null, [
        'script_dir' => __DIR__,
        'working_dir' => getcwd(),
        'db_path' => defined('DB_PATH') ? DB_PATH : 'NOT DEFINED',
        'data_dir' => $dataDir,
        'data_dir_exists' => is_dir($dataDir),
        'data_dir_writable' => is_writable($dataDir)
    ]);
    exit(1);
}

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
                cronLog('info', "Query executed: SELECT id FROM mailboxes WHERE is_enabled = 1", $eventLogger, $userId);
                
                // Check if there are any mailboxes at all
                $allMailboxesStmt = $db->query("SELECT COUNT(*) as count FROM mailboxes");
                $allMailboxes = $allMailboxesStmt->fetch();
                cronLog('info', "Total mailboxes in database: " . ($allMailboxes['count'] ?? 0), $eventLogger, $userId);
                
                // Check enabled status
                $enabledStmt = $db->query("SELECT id, name, is_enabled FROM mailboxes");
                $allMailboxDetails = $enabledStmt->fetchAll();
                foreach ($allMailboxDetails as $mb) {
                    cronLog('info', "Mailbox ID {$mb['id']} ({$mb['name']}): is_enabled = " . ($mb['is_enabled'] ? '1' : '0'), $eventLogger, $userId);
                }
            } else {
                cronLog('info', "Found " . count($mailboxes) . " enabled mailbox(es) to process", $eventLogger, $userId);
                
                foreach ($mailboxes as $mailbox) {
                    $mailboxId = $mailbox['id'];
                    try {
                        cronLog('info', "Processing mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        
                        // Get mailbox details for logging
                        $mailboxStmt = $db->prepare("SELECT name, email, is_enabled FROM mailboxes WHERE id = ?");
                        $mailboxStmt->execute([$mailboxId]);
                        $mailboxDetails = $mailboxStmt->fetch();
                        
                        if ($mailboxDetails) {
                            cronLog('info', "Mailbox details - Name: {$mailboxDetails['name']}, Email: {$mailboxDetails['email']}, Enabled: {$mailboxDetails['is_enabled']}", 
                                $eventLogger, $userId, $mailboxId);
                        }
                        
                        cronLog('info', "Creating MailboxMonitor instance for mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        $monitor = new MailboxMonitor($mailboxId);
                        cronLog('info', "MailboxMonitor instance created successfully", $eventLogger, $userId, $mailboxId);
                        
                        cronLog('info', "Calling processInbox() for mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        $result = $monitor->processInbox();
                        
                        cronLog('info', "processInbox() returned result: " . print_r($result, true), $eventLogger, $userId, $mailboxId);
                        
                        if (!is_array($result)) {
                            cronLogError("processInbox() returned invalid result (not an array)", null, $eventLogger, $userId, $mailboxId, [
                                'result_type' => gettype($result),
                                'result_value' => print_r($result, true)
                            ]);
                        } else {
                            $processed = $result['processed'] ?? 0;
                            $skipped = $result['skipped'] ?? 0;
                            $problems = $result['problems'] ?? 0;
                            
                            cronLog('info', "Mailbox {$mailboxId} processed: {$processed} processed, {$skipped} skipped, {$problems} problems", 
                                $eventLogger, $userId, $mailboxId);
                        }
                        
                        cronLog('info', "Disconnecting from mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        $monitor->disconnect();
                        cronLog('info', "Disconnected from mailbox ID: {$mailboxId}", $eventLogger, $userId, $mailboxId);
                        
                    } catch (Exception $e) {
                        cronLogError("Error processing mailbox {$mailboxId}", $e, $eventLogger, $userId, $mailboxId, [
                            'mailbox_id' => $mailboxId,
                            'mailbox_name' => $mailboxDetails['name'] ?? 'unknown',
                            'mailbox_email' => $mailboxDetails['email'] ?? 'unknown'
                        ]);
                        $exitCode = 2; // Processing error
                        // Continue with other mailboxes
                    }
                }
            }
            
            cronLog('info', "Mailbox processing phase completed", $eventLogger, $userId);
        } catch (Exception $e) {
            cronLogError("Fatal error during mailbox processing", $e, $eventLogger, $userId, null, [
                'phase' => 'mailbox_processing',
                'mailbox_count' => isset($mailboxes) ? count($mailboxes) : 'unknown'
            ]);
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
            cronLogError("Fatal error during notification sending", $e, $eventLogger, $userId, null, [
                'phase' => 'notification_sending',
                'real_time_mode' => isset($realTime) ? ($realTime ? 'YES' : 'NO') : 'unknown',
                'pending_count' => isset($count) ? $count : 'unknown'
            ]);
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
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            cronLogError("Fatal error during notification deduplication", $e, $eventLogger, $userId, null, [
                'phase' => 'deduplication',
                'duplicate_pairs_count' => isset($duplicatePairs) ? count($duplicatePairs) : 'unknown'
            ]);
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
    cronLogError("Fatal error in cron script", $e, $eventLogger ?? null, $userId, null, [
        'script_path' => __FILE__,
        'working_directory' => getcwd(),
        'php_version' => PHP_VERSION,
        'is_cli' => $isCli,
        'process_only' => $processOnly,
        'send_only' => $sendOnly,
        'dedupe' => $dedupe
    ]);
    $exitCode = 1;
}

// Exit with appropriate code
exit($exitCode);

