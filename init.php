<?php
/**
 * Initialization script to test database setup
 * Run this once to verify everything is working
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use BounceNG\Database;

echo "Initializing Bounce Monitor Database...\n";

try {
    $db = Database::getInstance();
    echo "✓ Database initialized successfully\n";
    
    // Test query
    $stmt = $db->query("SELECT COUNT(*) as count FROM smtp_codes");
    $result = $stmt->fetch();
    echo "✓ SMTP codes table has {$result['count']} entries\n";
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM notification_template");
    $result = $stmt->fetch();
    echo "✓ Notification template " . ($result['count'] > 0 ? "exists" : "created") . "\n";
    
    echo "\n✓ Initialization complete!\n";
    echo "You can now access the application at: " . APP_URL . "\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

