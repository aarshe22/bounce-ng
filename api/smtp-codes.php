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

try {
    switch ($method) {
        case 'GET':
            if ($path === 'list') {
                $stmt = $db->query("SELECT * FROM smtp_codes ORDER BY code");
                $codes = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $codes]);
            } elseif ($path === 'get' && isset($_GET['code'])) {
                $stmt = $db->prepare("SELECT * FROM smtp_codes WHERE code = ?");
                $stmt->execute([$_GET['code']]);
                $code = $stmt->fetch();
                if ($code) {
                    echo json_encode(['success' => true, 'data' => $code]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'SMTP code not found']);
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
                    INSERT INTO smtp_codes (code, description, recommendation, category)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['code'],
                    $data['description'],
                    $data['recommendation'] ?? null,
                    $data['category'] ?? null
                ]);
                
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
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
                    UPDATE smtp_codes SET
                        code = ?,
                        description = ?,
                        recommendation = ?,
                        category = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['code'],
                    $data['description'],
                    $data['recommendation'] ?? null,
                    $data['category'] ?? null,
                    $data['id']
                ]);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'DELETE':
            $auth->requireAdmin();
            
            if ($path === 'delete' && isset($_GET['id'])) {
                $stmt = $db->prepare("DELETE FROM smtp_codes WHERE id = ?");
                $stmt->execute([$_GET['id']]);
                
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

