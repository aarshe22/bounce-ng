<?php
/**
 * Setup script for Bounce Monitor
 * Creates necessary directories and sets permissions
 * 
 * This script is run automatically after composer install/update
 */

$baseDir = __DIR__ . '/..';

// Directories to create
$directories = [
    'data' => 0755,
];

// Files to create (if they don't exist)
$files = [
    'data/.gitkeep' => 0644,
];

echo "Setting up Bounce Monitor directories and permissions...\n";

// Create directories
foreach ($directories as $dir => $permissions) {
    $fullPath = $baseDir . '/' . $dir;
    
    if (!is_dir($fullPath)) {
        if (mkdir($fullPath, $permissions, true)) {
            echo "✓ Created directory: {$dir}\n";
        } else {
            echo "✗ Failed to create directory: {$dir}\n";
            exit(1);
        }
    } else {
        // Directory exists, ensure permissions are correct
        @chmod($fullPath, $permissions);
        echo "✓ Directory exists: {$dir}\n";
    }
    
    // Verify directory is writable
    if (!is_writable($fullPath)) {
        echo "⚠ Warning: Directory {$dir} is not writable. You may need to set permissions manually:\n";
        echo "  chmod {$permissions} {$fullPath}\n";
    }
}

// Create placeholder files
foreach ($files as $file => $permissions) {
    $fullPath = $baseDir . '/' . $file;
    
    if (!file_exists($fullPath)) {
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (touch($fullPath)) {
            @chmod($fullPath, $permissions);
            echo "✓ Created file: {$file}\n";
        } else {
            echo "⚠ Warning: Could not create file: {$file}\n";
        }
    }
}

// Check if .env file exists
$envFile = $baseDir . '/.env';
if (!file_exists($envFile)) {
    $envExample = $baseDir . '/.env.example';
    if (file_exists($envExample)) {
        echo "\n⚠ Warning: .env file not found. Please copy .env.example to .env and configure:\n";
        echo "  cp .env.example .env\n";
    }
}

echo "\n✓ Setup complete!\n";
echo "\nNext steps:\n";
echo "1. Copy .env.example to .env and configure OAuth credentials\n";
echo "2. Ensure the data/ directory is writable by your web server\n";
echo "3. Set up OAuth applications (Google/Microsoft)\n";
echo "4. Start the application\n\n";

