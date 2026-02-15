<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\MssqlSync;

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

try {
    $sync = new MssqlSync();

    switch ($method) {
        case 'GET':
            if ($path === 'config') {
                echo json_encode(['success' => true, 'data' => $sync->getConfigMasked()]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'POST':
            $auth->requireAdmin();
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            if ($path === 'set-config') {
                $keys = ['mssql_server', 'mssql_port', 'mssql_database', 'mssql_table', 'mssql_username', 'mssql_password'];
                $db = \BounceNG\Database::getInstance();
                foreach ($keys as $key) {
                    $value = isset($data[$key]) ? (string) $data[$key] : '';
                    if ($key === 'mssql_port' && $value === '') {
                        $value = '1433';
                    }
                    // Do not overwrite password with placeholder or empty
                    if ($key === 'mssql_password' && ($value === '' || $value === '********')) {
                        continue;
                    }
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
                echo json_encode(['success' => true, 'message' => 'MSSQL sync settings saved']);
            } elseif ($path === 'test') {
                $sync->testConnection();
                echo json_encode(['success' => true, 'message' => 'Connection successful']);
            } elseif ($path === 'sync') {
                $result = $sync->syncToMssql();
                echo json_encode([
                    'success' => true,
                    'message' => $result['success'] . ' address(es) synced',
                    'data' => $result,
                ]);
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
