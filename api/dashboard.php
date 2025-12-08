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
    
    // Validate domains and collect associated email addresses
    require_once __DIR__ . '/../src/DomainValidator.php';
    foreach ($domains as &$domain) {
        $validation = \BounceNG\DomainValidator::validateDomain($domain['domain']);
        $domain['is_valid'] = $validation['valid'];
        $domain['validation_reason'] = $validation['reason'];
        
        // Get all email addresses (TO and CC) associated with this domain
        $stmt = $db->prepare("
            SELECT DISTINCT 
                b.original_to,
                b.original_cc
            FROM bounces b
            WHERE b.recipient_domain = ?
        ");
        $stmt->execute([$domain['domain']]);
        $bounces = $stmt->fetchAll();
        
        $toAddresses = [];
        $ccAddresses = [];
        $emailPairs = []; // Store TO:CC pairs
        
        foreach ($bounces as $bounce) {
            // Extract TO address
            $to = trim($bounce['original_to'] ?? '');
            $toValid = !empty($to) && filter_var($to, FILTER_VALIDATE_EMAIL);
            
            // Extract CC addresses (stored as comma-separated string)
            $ccString = trim($bounce['original_cc'] ?? '');
            $ccList = [];
            
            if (!empty($ccString)) {
                // Parse comma-separated CC addresses
                $ccParts = preg_split('/[,\s]+/', $ccString);
                foreach ($ccParts as $cc) {
                    $cc = trim($cc);
                    if (!empty($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                        $ccList[] = $cc;
                    }
                }
            }
            
            // For invalid domains, collect all TO:CC pairs where CC matches the domain
            // This helps admins contact users with typos
            foreach ($ccList as $cc) {
                $ccDomain = strtolower(substr(strrchr($cc, "@"), 1));
                if ($ccDomain === strtolower($domain['domain'])) {
                    $ccAddresses[] = $cc;
                    // Store TO:CC pair (TO might be different domain, that's OK)
                    if ($toValid) {
                        $emailPairs[] = ['to' => $to, 'cc' => $cc];
                    }
                }
            }
            
            // Also collect TO addresses that match the domain
            if ($toValid) {
                $toDomain = strtolower(substr(strrchr($to, "@"), 1));
                if ($toDomain === strtolower($domain['domain'])) {
                    $toAddresses[] = $to;
                }
            }
        }
        
        // Remove duplicates
        $toAddresses = array_values(array_unique($toAddresses));
        $ccAddresses = array_values(array_unique($ccAddresses));
        
        $domain['associated_to_addresses'] = $toAddresses;
        $domain['associated_cc_addresses'] = $ccAddresses;
        $domain['email_pairs'] = $emailPairs; // TO:CC pairs for invalid domains
        
        // Get recent bounces for this domain (last 10)
        $stmt = $db->prepare("
            SELECT 
                b.id,
                b.bounce_date,
                b.smtp_code,
                b.smtp_reason,
                b.deliverability_status,
                b.original_to,
                b.original_subject,
                sc.description as smtp_description
            FROM bounces b
            LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
            WHERE b.recipient_domain = ?
            ORDER BY b.bounce_date DESC
            LIMIT 10
        ");
        $stmt->execute([$domain['domain']]);
        $domain['recent_bounces'] = $stmt->fetchAll();
        
        // Get SMTP codes breakdown for this domain
        $stmt = $db->prepare("
            SELECT 
                b.smtp_code,
                COUNT(*) as count,
                sc.description,
                sc.recommendation
            FROM bounces b
            LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
            WHERE b.recipient_domain = ? AND b.smtp_code IS NOT NULL
            GROUP BY b.smtp_code, sc.description, sc.recommendation
            ORDER BY count DESC
        ");
        $stmt->execute([$domain['domain']]);
        $domain['smtp_codes'] = $stmt->fetchAll();
        
        // Get bounce timeline (last 30 days)
        $stmt = $db->prepare("
            SELECT 
                DATE(bounce_date) as bounce_day,
                COUNT(*) as bounce_count,
                COUNT(CASE WHEN deliverability_status = 'permanent_failure' THEN 1 END) as permanent_count,
                COUNT(CASE WHEN deliverability_status = 'temporary_failure' THEN 1 END) as temporary_count
            FROM bounces
            WHERE recipient_domain = ? 
            AND bounce_date >= datetime('now', '-30 days')
            GROUP BY DATE(bounce_date)
            ORDER BY bounce_day DESC
            LIMIT 30
        ");
        $stmt->execute([$domain['domain']]);
        $domain['bounce_timeline'] = $stmt->fetchAll();
    }
    unset($domain); // Break reference

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
    
    // Get domains for each SMTP code
    foreach ($smtpCodes as &$code) {
        $stmt = $db->prepare("
            SELECT 
                b.recipient_domain,
                COUNT(*) as bounce_count,
                MAX(b.bounce_date) as last_bounce
            FROM bounces b
            WHERE b.smtp_code = ?
            GROUP BY b.recipient_domain
            ORDER BY bounce_count DESC
        ");
        $stmt->execute([$code['smtp_code']]);
        $code['domains'] = $stmt->fetchAll();
    }
    unset($code); // Break reference

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

