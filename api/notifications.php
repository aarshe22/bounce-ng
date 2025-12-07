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
                // Send all pending notifications - call notify-cron.php with send_only parameter
                header('Content-Type: application/json');
                header('Connection: close');
                
                $response = json_encode(['success' => true, 'status' => 'processing', 'message' => 'Sending notifications in background']);
                header('Content-Length: ' . strlen($response));
                echo $response;
                
                // Flush and finish request
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                
                // Set execution limits
                set_time_limit(1800);
                ini_set('max_execution_time', 1800);
                ignore_user_abort(true);
                
                // Execute notify-cron.php in CLI mode with --send-only flag
                $cronScript = __DIR__ . '/../notify-cron.php';
                $phpBinary = PHP_BINARY;
                $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($cronScript) . ' --send-only 2>&1';
                
                // Execute in background (non-blocking)
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose(popen("start /B " . $command, "r"));
                } else {
                    exec($command . ' > /dev/null 2>&1 &');
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

