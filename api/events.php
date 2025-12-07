<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\EventLogger;

// Set headers first to prevent any output before JSON
header('Content-Type: application/json');

// Prevent any output buffering issues
if (ob_get_level() > 0) {
    ob_clean();
}

$auth = new Auth();
$eventLogger = new EventLogger();

try {
    $auth->requireAuth();
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $filters = [];

    if (isset($_GET['severity'])) {
        $filters['severity'] = $_GET['severity'];
    }
    if (isset($_GET['event_type'])) {
        $filters['event_type'] = $_GET['event_type'];
    }
    if (isset($_GET['mailbox_id'])) {
        $filters['mailbox_id'] = $_GET['mailbox_id'];
    }

    $events = $eventLogger->getEvents($limit, $filters);
    
    // Ensure events is always an array
    if (!is_array($events)) {
        $events = [];
    }

    echo json_encode(['success' => true, 'data' => $events]);
    exit(0);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit(1);
}

