<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Migrations;

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $migrations = new Migrations();

    switch ($method) {
        case 'GET':
            if ($action === 'list' || $action === '') {
                $list = $migrations->listWithStatus();
                echo json_encode(['success' => true, 'data' => $list]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;

        case 'POST':
            $auth->requireAdmin();
            if ($action === 'apply') {
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $id = trim($data['id'] ?? '');
                if ($id === '') {
                    throw new Exception('Migration ID is required.');
                }
                $migrations->apply($id);
                echo json_encode(['success' => true, 'message' => 'Migration applied.']);
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
