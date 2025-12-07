<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;

header('Content-Type: application/json');

$auth = new Auth();
$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';

$auth->requireAuth();

try {
    if ($method === 'POST' && $path === 'run') {
        // Execute notify-cron.php in CLI mode (exactly as cron would)
        header('Content-Type: application/json');
        header('Connection: close');
        
        $response = json_encode(['success' => true, 'status' => 'running', 'message' => 'Cron script started']);
        header('Content-Length: ' . strlen($response));
        echo $response;
        
        // Flush and finish request
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Set execution limits
        set_time_limit(1800);
        ini_set('max_execution_time', 1800);
        ignore_user_abort(true);
        
        // Execute notify-cron.php in CLI mode
        $cronScript = __DIR__ . '/../notify-cron.php';
        $phpBinary = PHP_BINARY;
        
        // Ensure script exists
        if (!file_exists($cronScript)) {
            error_log("Cron script not found: {$cronScript}");
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Cron script not found']);
            exit(1);
        }
        
        // Build command - execute exactly as cron would
        // Use absolute path and ensure we're in the right directory
        $cronScript = realpath($cronScript);
        $workingDir = dirname($cronScript);
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($cronScript) . ' 2>&1';
        
        // Log the command for debugging
        error_log("Executing cron command: {$command} in directory: {$workingDir}");
        
        // Execute in background (non-blocking)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $handle = popen("start /B " . $command, "r");
            if ($handle === false) {
                error_log("Failed to start cron script on Windows");
            } else {
                pclose($handle);
            }
        } else {
            // Unix/Linux - use nohup to ensure it continues after parent exits
            // Change to script directory and execute
            // Use full path to nohup if available, otherwise just run in background
            $nohupPath = trim(shell_exec('which nohup 2>/dev/null') ?: 'nohup');
            $fullCommand = "cd " . escapeshellarg($workingDir) . " && " . escapeshellarg($nohupPath) . " " . $command . " >> " . escapeshellarg($workingDir . '/notify-cron.log') . " 2>&1 & echo $!";
            
            $pid = trim(shell_exec($fullCommand));
            
            if (empty($pid) || !is_numeric($pid)) {
                // Fallback: try without nohup
                $fallbackCommand = "cd " . escapeshellarg($workingDir) . " && " . $command . " >> " . escapeshellarg($workingDir . '/notify-cron.log') . " 2>&1 &";
                exec($fallbackCommand, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    error_log("Failed to execute cron script. Return code: {$returnVar}, Output: " . implode("\n", $output));
                } else {
                    error_log("Cron script started successfully (fallback method)");
                }
            } else {
                error_log("Cron script started successfully with PID: {$pid}");
            }
        }
        
        // Don't return anything - request already finished
        exit(0);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

