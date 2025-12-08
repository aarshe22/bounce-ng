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
    // Get top recipient domains with additional stats
    $stmt = $db->query("
        SELECT 
            rd.domain, 
            rd.bounce_count, 
            rd.trust_score, 
            rd.last_bounce_date,
            COUNT(DISTINCT b.id) as total_bounces,
            MIN(b.bounce_date) as first_bounce_date,
            MAX(b.bounce_date) as last_bounce_date_actual,
            COUNT(DISTINCT CASE WHEN b.deliverability_status = 'permanent_failure' THEN b.id END) as permanent_failures,
            COUNT(DISTINCT CASE WHEN b.deliverability_status = 'temporary_failure' THEN b.id END) as temporary_failures
        FROM recipient_domains rd
        LEFT JOIN bounces b ON rd.domain = b.recipient_domain
        GROUP BY rd.domain, rd.bounce_count, rd.trust_score, rd.last_bounce_date
        ORDER BY rd.bounce_count DESC
        LIMIT 20
    ");
    $domains = $stmt->fetchAll();

    // Get all SMTP codes with descriptions and additional stats
    $stmt = $db->query("
        SELECT 
            b.smtp_code, 
            COUNT(*) as count, 
            sc.description, 
            sc.recommendation,
            COUNT(DISTINCT b.recipient_domain) as affected_domains,
            MIN(b.bounce_date) as first_seen,
            MAX(b.bounce_date) as last_seen
        FROM bounces b
        LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
        WHERE b.smtp_code IS NOT NULL
        GROUP BY b.smtp_code, sc.description, sc.recommendation
        ORDER BY count DESC
    ");
    $smtpCodes = $stmt->fetchAll();

    // Get statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM bounces");
    $totalBounces = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM recipient_domains");
    $totalDomains = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM mailboxes WHERE is_enabled = 1");
    $activeMailboxes = $stmt->fetch()['total'];

    $stmt = $db->query("SELECT COUNT(*) as total FROM notifications_queue WHERE status = 'pending'");
    $queuedNotifications = $stmt->fetch()['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'domains' => $domains,
            'smtpCodes' => $smtpCodes,
            'stats' => [
                'totalBounces' => $totalBounces,
                'totalDomains' => $totalDomains,
                'activeMailboxes' => $activeMailboxes,
                'queuedNotifications' => $queuedNotifications
            ]
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

