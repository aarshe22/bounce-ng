<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$auth->requireAuth();
$auth->requireAdmin(); // Only admins can backup/restore

try {
    switch ($method) {
        case 'GET':
            if ($path === 'export') {
                // Export configuration data
                $backup = [
                    'version' => '1.0',
                    'exported_at' => date('Y-m-d H:i:s'),
                    'users' => [],
                    'relay_providers' => [],
                    'mailboxes' => [],
                    'notification_template' => null,
                    'settings' => []
                ];
                
                // Export users (without sensitive data)
                $stmt = $db->query("
                    SELECT id, email, name, provider, provider_id, is_admin, is_active, created_at, last_login
                    FROM users
                    ORDER BY id
                ");
                $backup['users'] = $stmt->fetchAll();
                
                // Export relay providers (with passwords - they're needed for functionality)
                $stmt = $db->query("
                    SELECT id, name, smtp_host, smtp_port, smtp_username, smtp_password, 
                           smtp_from_email, smtp_from_name, smtp_encryption, is_active, 
                           created_at, updated_at
                    FROM relay_providers
                    ORDER BY id
                ");
                $backup['relay_providers'] = $stmt->fetchAll();
                
                // Export mailboxes (with passwords - they're needed for functionality)
                $stmt = $db->query("
                    SELECT id, name, email, imap_server, imap_port, imap_protocol, 
                           imap_username, imap_password, folder_inbox, folder_processed, 
                           folder_problem, folder_skipped, relay_provider_id, is_enabled, 
                           created_at, updated_at
                    FROM mailboxes
                    ORDER BY id
                ");
                $backup['mailboxes'] = $stmt->fetchAll();
                
                // Export notification template
                $stmt = $db->query("SELECT * FROM notification_template ORDER BY id DESC LIMIT 1");
                $template = $stmt->fetch();
                if ($template) {
                    $backup['notification_template'] = [
                        'subject' => $template['subject'],
                        'body' => $template['body']
                    ];
                }
                
                // Export settings (excluding sensitive ones if any)
                $stmt = $db->query("SELECT key, value FROM settings ORDER BY key");
                $settings = [];
                while ($row = $stmt->fetch()) {
                    $settings[$row['key']] = $row['value'];
                }
                $backup['settings'] = $settings;
                
                // Set headers for download
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="bounce-ng-backup-' . date('Y-m-d-His') . '.json"');
                
                echo json_encode($backup, JSON_PRETTY_PRINT);
                exit;
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            if ($path === 'import') {
                // Import configuration data
                $input = file_get_contents('php://input');
                $backup = json_decode($input, true);
                
                if (!$backup || !is_array($backup)) {
                    throw new Exception("Invalid backup file format");
                }
                
                // Validate backup structure
                if (!isset($backup['version'])) {
                    throw new Exception("Backup file missing version information");
                }
                
                $db->beginTransaction();
                
                try {
                    // Import users
                    if (isset($backup['users']) && is_array($backup['users'])) {
                        foreach ($backup['users'] as $user) {
                            $stmt = $db->prepare("
                                INSERT OR REPLACE INTO users 
                                (id, email, name, provider, provider_id, is_admin, is_active, created_at, last_login)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $user['id'] ?? null,
                                $user['email'],
                                $user['name'] ?? null,
                                $user['provider'],
                                $user['provider_id'],
                                $user['is_admin'] ?? 0,
                                $user['is_active'] ?? 1,
                                $user['created_at'] ?? null,
                                $user['last_login'] ?? null
                            ]);
                        }
                    }
                    
                    // Import relay providers
                    if (isset($backup['relay_providers']) && is_array($backup['relay_providers'])) {
                        foreach ($backup['relay_providers'] as $provider) {
                            $stmt = $db->prepare("
                                INSERT OR REPLACE INTO relay_providers 
                                (id, name, smtp_host, smtp_port, smtp_username, smtp_password, 
                                 smtp_from_email, smtp_from_name, smtp_encryption, is_active, 
                                 created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $provider['id'] ?? null,
                                $provider['name'],
                                $provider['smtp_host'],
                                $provider['smtp_port'],
                                $provider['smtp_username'],
                                $provider['smtp_password'],
                                $provider['smtp_from_email'],
                                $provider['smtp_from_name'],
                                $provider['smtp_encryption'] ?? 'tls',
                                $provider['is_active'] ?? 1,
                                $provider['created_at'] ?? null,
                                $provider['updated_at'] ?? null
                            ]);
                        }
                    }
                    
                    // Import mailboxes
                    if (isset($backup['mailboxes']) && is_array($backup['mailboxes'])) {
                        foreach ($backup['mailboxes'] as $mailbox) {
                            $stmt = $db->prepare("
                                INSERT OR REPLACE INTO mailboxes 
                                (id, name, email, imap_server, imap_port, imap_protocol, 
                                 imap_username, imap_password, folder_inbox, folder_processed, 
                                 folder_problem, folder_skipped, relay_provider_id, is_enabled, 
                                 created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $mailbox['id'] ?? null,
                                $mailbox['name'],
                                $mailbox['email'],
                                $mailbox['imap_server'],
                                $mailbox['imap_port'],
                                $mailbox['imap_protocol'],
                                $mailbox['imap_username'],
                                $mailbox['imap_password'],
                                $mailbox['folder_inbox'] ?? 'INBOX',
                                $mailbox['folder_processed'] ?? 'Processed',
                                $mailbox['folder_problem'] ?? 'Problem',
                                $mailbox['folder_skipped'] ?? 'Skipped',
                                $mailbox['relay_provider_id'] ?? null,
                                $mailbox['is_enabled'] ?? 1,
                                $mailbox['created_at'] ?? null,
                                $mailbox['updated_at'] ?? null
                            ]);
                        }
                    }
                    
                    // Import notification template
                    if (isset($backup['notification_template']) && is_array($backup['notification_template'])) {
                        $template = $backup['notification_template'];
                        $stmt = $db->prepare("
                            INSERT INTO notification_template (subject, body, updated_at)
                            VALUES (?, ?, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([
                            $template['subject'],
                            $template['body']
                        ]);
                    }
                    
                    // Import settings
                    if (isset($backup['settings']) && is_array($backup['settings'])) {
                        foreach ($backup['settings'] as $key => $value) {
                            $checkStmt = $db->prepare("SELECT key FROM settings WHERE key = ?");
                            $checkStmt->execute([$key]);
                            if ($checkStmt->fetch()) {
                                $stmt = $db->prepare("UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = ?");
                                $stmt->execute([$value, $key]);
                            } else {
                                $stmt = $db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                                $stmt->execute([$key, $value]);
                            }
                        }
                    }
                    
                    $db->commit();
                    
                    $eventLogger = new \BounceNG\EventLogger();
                    $eventLogger->log('info', "Configuration restored from backup file", $_SESSION['user_id'] ?? null);
                    
                    echo json_encode(['success' => true, 'message' => 'Configuration restored successfully']);
                } catch (Exception $e) {
                    $db->rollBack();
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

