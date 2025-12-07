<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\Database;

header('Content-Type: application/json');

$auth = new Auth();
$db = Database::getInstance();

$auth->requireAuth();

try {
    // Get top recipient domains
    $stmt = $db->query("
        SELECT domain, bounce_count, trust_score, last_bounce_date
        FROM recipient_domains
        ORDER BY bounce_count DESC
        LIMIT 20
    ");
    $domains = $stmt->fetchAll();

    // Get top SMTP codes with descriptions
    $stmt = $db->query("
        SELECT b.smtp_code, COUNT(*) as count, sc.description, sc.recommendation
        FROM bounces b
        LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
        WHERE b.smtp_code IS NOT NULL
        GROUP BY b.smtp_code, sc.description, sc.recommendation
        ORDER BY count DESC
        LIMIT 10
    ");
    $smtpCodes = $stmt->fetchAll();

    // Get statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM bounces");
    $totalBounces = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM recipient_domains");
    $totalDomains = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM mailboxes WHERE is_enabled = 1");
    $activeMailboxes = $stmt->fetch()['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'domains' => $domains,
            'smtpCodes' => $smtpCodes,
            'stats' => [
                'totalBounces' => $totalBounces,
                'totalDomains' => $totalDomains,
                'activeMailboxes' => $activeMailboxes
            ]
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

