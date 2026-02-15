<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;
use PDO;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();
$auth->requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $sort = $_GET['sort'] ?? 'bounce_date';
    $order = strtoupper($_GET['order'] ?? 'DESC');
    if (!in_array($order, ['ASC', 'DESC'], true)) {
        $order = 'DESC';
    }
    $search = trim($_GET['search'] ?? '');
    $limit = (int) ($_GET['limit'] ?? 2000);
    $limit = max(1, min(5000, $limit));
    $offset = (int) ($_GET['offset'] ?? 0);
    $offset = max(0, $offset);

    $allowedSort = ['bounce_date', 'original_to', 'recipient_domain', 'smtp_code', 'deliverability_status'];
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'bounce_date';
    }

    $params = [];
    $where = '1=1';
    if ($search !== '') {
        $where .= " AND (
            LOWER(b.original_to) LIKE LOWER(?) OR
            LOWER(b.recipient_domain) LIKE LOWER(?) OR
            b.smtp_code LIKE ? OR
            LOWER(b.smtp_reason) LIKE LOWER(?)
        )";
        $term = '%' . $search . '%';
        $params = array_merge($params, [$term, $term, $term, $term]);
    }

    $stmt = $db->prepare("
        SELECT
            b.id,
            b.bounce_date,
            b.original_to,
            b.recipient_domain,
            b.smtp_code,
            b.smtp_reason,
            b.deliverability_status
        FROM bounces b
        WHERE {$where}
        ORDER BY b.{$sort} {$order}
        LIMIT " . (int) $limit . " OFFSET " . (int) $offset . "
    ");
    $stmt->execute($params);
    $bounces = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM bounces b WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => $bounces,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
