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

$auth->requireAuth();

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                $stmt = $db->query("SELECT id, name, smtp_host, smtp_port, smtp_from_email, smtp_from_name, smtp_encryption, is_active, created_at FROM relay_providers ORDER BY name");
                $providers = $stmt->fetchAll();
                // Don't return passwords
                echo json_encode(['success' => true, 'data' => $providers]);
            } elseif ($path === 'get' && isset($_GET['id'])) {
                $stmt = $db->prepare("SELECT * FROM relay_providers WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                $provider = $stmt->fetch();
                if ($provider) {
                    // Don't return password in response
                    unset($provider['smtp_password']);
                    echo json_encode(['success' => true, 'data' => $provider]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Relay provider not found']);
                }
            } elseif ($path === 'test' && isset($_GET['id'])) {
                // Test relay provider connection
                $stmt = $db->prepare("SELECT * FROM relay_providers WHERE id = ? AND is_active = 1");
                $stmt->execute([$_GET['id']]);
                $provider = $stmt->fetch();
                
                if (!$provider) {
                    throw new Exception("Relay provider not found");
                }

                try {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = $provider['smtp_host'];
                    $mail->SMTPAuth = true;
                    $mail->Username = $provider['smtp_username'];
                    $mail->Password = $provider['smtp_password'];
                    
                    $encryption = strtolower($provider['smtp_encryption']);
                    if ($encryption === 'ssl') {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    } else {
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    }
                    
                    $mail->Port = (int)$provider['smtp_port'];
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];
                    
                    // Just test connection, don't send
                    $mail->smtpConnect();
                    $mail->smtpClose();
                    
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
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'create') {
                $stmt = $db->prepare("
                    INSERT INTO relay_providers (
                        name, smtp_host, smtp_port, smtp_username, smtp_password,
                        smtp_from_email, smtp_from_name, smtp_encryption, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $data['name'],
                    $data['smtp_host'],
                    $data['smtp_port'],
                    $data['smtp_username'],
                    $data['smtp_password'],
                    $data['smtp_from_email'],
                    $data['smtp_from_name'],
                    $data['smtp_encryption'] ?? 'tls',
                    $data['is_active'] ?? 1
                ]);
                
                $providerId = $db->lastInsertId();
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Relay provider created: {$data['name']}", $_SESSION['user_id']);
                
                echo json_encode(['success' => true, 'id' => $providerId]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'PUT':
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true);
            
            if ($path === 'update' && isset($data['id'])) {
                // Check if password is being updated
                if (isset($data['smtp_password']) && !empty($data['smtp_password'])) {
                    $stmt = $db->prepare("
                        UPDATE relay_providers SET
                            name = ?, smtp_host = ?, smtp_port = ?,
                            smtp_username = ?, smtp_password = ?,
                            smtp_from_email = ?, smtp_from_name = ?,
                            smtp_encryption = ?, is_active = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['name'],
                        $data['smtp_host'],
                        $data['smtp_port'],
                        $data['smtp_username'],
                        $data['smtp_password'],
                        $data['smtp_from_email'],
                        $data['smtp_from_name'],
                        $data['smtp_encryption'] ?? 'tls',
                        $data['is_active'] ?? 1,
                        $data['id']
                    ]);
                } else {
                    // Don't update password if not provided
                    $stmt = $db->prepare("
                        UPDATE relay_providers SET
                            name = ?, smtp_host = ?, smtp_port = ?,
                            smtp_username = ?,
                            smtp_from_email = ?, smtp_from_name = ?,
                            smtp_encryption = ?, is_active = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['name'],
                        $data['smtp_host'],
                        $data['smtp_port'],
                        $data['smtp_username'],
                        $data['smtp_from_email'],
                        $data['smtp_from_name'],
                        $data['smtp_encryption'] ?? 'tls',
                        $data['is_active'] ?? 1,
                        $data['id']
                    ]);
                }
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Relay provider updated: {$data['name']}", $_SESSION['user_id']);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            $auth->requireAdmin();
            
            if ($path === 'delete' && isset($_GET['id'])) {
                // Check if any mailboxes are using this provider
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM mailboxes WHERE relay_provider_id = ?");
                $stmt->execute([$_GET['id']]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Cannot delete: Mailboxes are using this relay provider']);
                    break;
                }
                
                $stmt = $db->prepare("DELETE FROM relay_providers WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                
                $eventLogger = new EventLogger();
                $eventLogger->log('info', "Relay provider deleted: ID {$_GET['id']}", $_SESSION['user_id']);
                
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

