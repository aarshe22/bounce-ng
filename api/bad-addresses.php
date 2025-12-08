<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json');

$auth = new \BounceNG\Auth();
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $db = \BounceNG\Database::getInstance();
    
    // Get all original TO addresses with bounce counts, ordered by count descending
    // SQLite GROUP_CONCAT doesn't support DISTINCT in older versions, so we'll handle uniqueness in PHP
    $stmt = $db->query("
        SELECT 
            original_to,
            COUNT(*) as bounce_count,
            MIN(bounce_date) as first_bounce,
            MAX(bounce_date) as last_bounce,
            GROUP_CONCAT(smtp_code) as smtp_codes,
            GROUP_CONCAT(recipient_domain) as domains
        FROM bounces
        WHERE original_to IS NOT NULL AND original_to != ''
        GROUP BY original_to
        ORDER BY bounce_count DESC, last_bounce DESC
    ");
    
    $badAddresses = $stmt->fetchAll();
    
    // Format the data - remove duplicates from GROUP_CONCAT results and handle NULLs
    foreach ($badAddresses as &$address) {
        $smtpCodesList = !empty($address['smtp_codes']) && $address['smtp_codes'] !== null
            ? explode(',', $address['smtp_codes']) 
            : [];
        $address['smtp_codes'] = array_values(array_unique(array_filter(array_map('trim', $smtpCodesList), function($v) {
            return $v !== '' && $v !== null;
        })));
        
        $domainsList = !empty($address['domains']) && $address['domains'] !== null
            ? explode(',', $address['domains']) 
            : [];
        $address['domains'] = array_values(array_unique(array_filter(array_map('trim', $domainsList), function($v) {
            return $v !== '' && $v !== null;
        })));
    }
    unset($address); // Break reference
    
    echo json_encode([
        'success' => true,
        'data' => $badAddresses,
        'total' => count($badAddresses)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Bad addresses API error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

