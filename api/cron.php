<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use BounceNG\Auth;
use BounceNG\EventLogger;

header('Content-Type: application/json');

// Initialize event logger for debug logging
$eventLogger = new EventLogger();
$userId = null;

try {
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    $eventLogger->log('debug', '[DEBUG] api/cron.php: Script started', $userId);
    
    $auth = new Auth();
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_GET['action'] ?? '';
    
    $eventLogger->log('debug', "[DEBUG] api/cron.php: Method={$method}, Action={$path}", $userId);
    
    $auth->requireAuth();
    $auth->requireAdmin(); // Only admins can run cron
    $eventLogger->log('debug', '[DEBUG] api/cron.php: Authentication passed', $userId);

    if ($method === 'POST' && $path === 'run') {
        $eventLogger->log('info', '[DEBUG] api/cron.php: Processing run action', $userId);
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
        
        // Find the CLI PHP binary (not PHP-FPM)
        // PHP_BINARY might point to php-fpm when running in web context (via web interface)
        // When running from Plesk cron automatically, PHP_BINARY should already be CLI
        $phpBinary = PHP_BINARY;
        $needsFpmDetection = false;
        
        // Only check for FPM if the path suggests it might be FPM
        // This ensures we don't interfere with automatic Plesk cron execution
        if (strpos($phpBinary, 'php-fpm') !== false || 
            strpos($phpBinary, '/sbin/php') !== false ||
            (strpos($phpBinary, 'fpm') !== false && strpos($phpBinary, '/bin/php') === false)) {
            $needsFpmDetection = true;
            $eventLogger->log('debug', "[DEBUG] api/cron.php: PHP_BINARY appears to be FPM, detecting CLI version", $userId);
        }
        
        if ($needsFpmDetection) {
            // Method 1: If Plesk path, try to convert FPM path to CLI path first (most reliable for Plesk)
            // For Plesk: /opt/plesk/php/8.3/sbin/php-fpm -> /opt/plesk/php/8.3/bin/php
            if (preg_match('#(/opt/plesk/php/[^/]+)/#', PHP_BINARY, $matches)) {
                $pleskBase = $matches[1];
                $cliPath = $pleskBase . '/bin/php';
                $eventLogger->log('debug', "[DEBUG] api/cron.php: Trying Plesk CLI path conversion: {$cliPath}", $userId);
                
                if (file_exists($cliPath)) {
                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk CLI path exists: {$cliPath}", $userId);
                    if (is_executable($cliPath)) {
                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk CLI path is executable: {$cliPath}", $userId);
                        // Verify it's CLI
                        $testOutput = shell_exec(escapeshellarg($cliPath) . ' -v 2>&1');
                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk CLI test output: " . substr($testOutput, 0, 100), $userId);
                        if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                            strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                            $phpBinary = $cliPath;
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP via Plesk path conversion: {$phpBinary}", $userId);
                            $needsFpmDetection = false;
                        } else {
                            $eventLogger->log('warning', "[DEBUG] api/cron.php: Plesk CLI path exists but appears to be FPM: {$cliPath}", $userId);
                        }
                    } else {
                        $eventLogger->log('warning', "[DEBUG] api/cron.php: Plesk CLI path exists but not executable: {$cliPath}", $userId);
                    }
                } else {
                    $eventLogger->log('warning', "[DEBUG] api/cron.php: Plesk CLI path does not exist: {$cliPath}", $userId);
                }
            }
            
            // Method 2: Try to resolve 'php' command path (most reliable)
            if ($needsFpmDetection) {
                // Try command -v first (more reliable than which)
                $cliPhp = trim(shell_exec('command -v php 2>/dev/null'));
                if (empty($cliPhp)) {
                    $cliPhp = trim(shell_exec('which php 2>/dev/null'));
                }
                $eventLogger->log('debug', "[DEBUG] api/cron.php: Resolved 'php' command to: " . ($cliPhp ?: 'empty'), $userId);
                
                if (!empty($cliPhp)) {
                    // Check if file is accessible
                    $fileAccessible = file_exists($cliPhp) && is_executable($cliPhp);
                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Resolved path accessible: " . ($fileAccessible ? 'yes' : 'no'), $userId);
                    
                    if ($fileAccessible) {
                        // Verify it's actually CLI by testing
                        $testOutput = shell_exec(escapeshellarg($cliPhp) . ' -v 2>&1');
                        if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                            strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                            $phpBinary = $cliPhp;
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP via command resolution: {$phpBinary}", $userId);
                            $needsFpmDetection = false;
                        } else {
                            $eventLogger->log('warning', "[DEBUG] api/cron.php: Resolved PHP path appears to be FPM: {$cliPhp}", $userId);
                        }
                    } else {
                        // File not accessible, but command might still work - test it
                        $testOutput = shell_exec('php -v 2>&1');
                        if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                            strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                            // Use 'php' command directly since it works even if file isn't accessible
                            $phpBinary = 'php';
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Using 'php' command (resolved to {$cliPhp} but not accessible, command works)", $userId);
                            $needsFpmDetection = false;
                        } else {
                            $eventLogger->log('warning', "[DEBUG] api/cron.php: Resolved PHP path not accessible and command test failed: {$cliPhp}", $userId);
                        }
                    }
                }
            }
            
            // Method 2b: Try /usr/bin/php directly (common symlink location)
            if ($needsFpmDetection) {
                $usrBinPhp = '/usr/bin/php';
                $eventLogger->log('debug', "[DEBUG] api/cron.php: Checking /usr/bin/php symlink", $userId);
                if (file_exists($usrBinPhp)) {
                    // Check if it's a symlink and resolve it
                    if (is_link($usrBinPhp)) {
                        $resolved = readlink($usrBinPhp);
                        if ($resolved && $resolved[0] !== '/') {
                            // Relative symlink, resolve it
                            $resolved = dirname($usrBinPhp) . '/' . $resolved;
                        }
                        $eventLogger->log('debug', "[DEBUG] api/cron.php: /usr/bin/php is symlink to: " . ($resolved ?: 'unknown'), $userId);
                    }
                    if (is_executable($usrBinPhp)) {
                        $testOutput = shell_exec(escapeshellarg($usrBinPhp) . ' -v 2>&1');
                        if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                            strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                            $phpBinary = $usrBinPhp;
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP via /usr/bin/php: {$phpBinary}", $userId);
                            $needsFpmDetection = false;
                        }
                    }
                }
            }
            
            // Method 3: Try to find PHP in the Plesk directory structure
            if ($needsFpmDetection) {
                // First, try to find PHP in the Plesk directory structure
                // Plesk might have PHP in different locations
                if (preg_match('#(/opt/plesk/php/[^/]+)/#', PHP_BINARY, $matches)) {
                    $pleskBase = $matches[1];
                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Searching Plesk directory: {$pleskBase}", $userId);
                    
                    // Check if Plesk base directory exists
                    if (!is_dir($pleskBase)) {
                        $eventLogger->log('warning', "[DEBUG] api/cron.php: Plesk base directory does not exist: {$pleskBase}", $userId);
                    } else {
                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk base directory exists, searching for PHP binaries", $userId);
                        
                        // Try to find php executable using find command (more reliable than scanning)
                        $findCommand = "find " . escapeshellarg($pleskBase) . " -name 'php' -type f -executable 2>/dev/null | head -5";
                        $foundPhps = shell_exec($findCommand);
                        if ($foundPhps) {
                            $foundPaths = array_filter(array_map('trim', explode("\n", $foundPhps)));
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found PHP binaries via find: " . implode(', ', $foundPaths), $userId);
                            foreach ($foundPaths as $foundPath) {
                                if (empty($foundPath)) continue;
                                // Skip FPM binaries
                                if (strpos($foundPath, 'php-fpm') !== false || strpos($foundPath, '/sbin/') !== false) {
                                    continue;
                                }
                                // Verify it's CLI
                                $testOutput = shell_exec(escapeshellarg($foundPath) . ' -v 2>&1');
                                if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                                    strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                                    $phpBinary = $foundPath;
                                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP via find command: {$phpBinary}", $userId);
                                    $needsFpmDetection = false;
                                    break;
                                }
                            }
                        }
                        
                        // Fallback: Try various possible locations within the Plesk PHP directory
                        if ($needsFpmDetection) {
                            $pleskPaths = [
                                $pleskBase . '/bin/php',
                                $pleskBase . '/bin/php-cli',
                                $pleskBase . '/usr/bin/php',
                                $pleskBase . '/usr/local/bin/php',
                            ];
                            
                            // Also try to find php executable in the directory
                            $binDirs = [$pleskBase . '/bin', $pleskBase . '/usr/bin', $pleskBase . '/usr/local/bin'];
                            foreach ($binDirs as $binDir) {
                                if (is_dir($binDir)) {
                                    $files = @scandir($binDir);
                                    if ($files) {
                                        foreach ($files as $file) {
                                            if ($file === 'php' || $file === 'php-cli') {
                                                $potentialPath = $binDir . '/' . $file;
                                                if (file_exists($potentialPath) && is_executable($potentialPath)) {
                                                    $pleskPaths[] = $potentialPath;
                                                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Found potential PHP binary: {$potentialPath}", $userId);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            
                            foreach ($pleskPaths as $path) {
                                $eventLogger->log('debug', "[DEBUG] api/cron.php: Checking Plesk path: {$path}", $userId);
                                if (file_exists($path)) {
                                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk path exists: {$path}", $userId);
                                    if (is_executable($path)) {
                                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk path is executable: {$path}", $userId);
                                        // Verify it's CLI by checking version output
                                        $testOutput = shell_exec(escapeshellarg($path) . ' -v 2>&1');
                                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Plesk path test output: " . substr($testOutput, 0, 100), $userId);
                                        if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                                            strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                                            $phpBinary = $path;
                                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP at Plesk path: {$phpBinary}", $userId);
                                            $needsFpmDetection = false;
                                            break; // Found it, exit the loop
                                        }
                                    } else {
                                        $eventLogger->log('warning', "[DEBUG] api/cron.php: Plesk path exists but not executable: {$path}", $userId);
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Method 4: Try common system-wide CLI PHP paths
                if ($needsFpmDetection) {
                    $commonPaths = [
                        '/usr/bin/php',  // Common symlink location (checked first)
                        '/opt/plesk/php/8.3/bin/php',  // Direct Plesk 8.3 path
                        '/opt/plesk/php/8.2/bin/php',
                        '/opt/plesk/php/8.1/bin/php',
                        '/opt/plesk/php/8.0/bin/php',
                        '/opt/plesk/php/7.4/bin/php',
                        '/usr/local/bin/php',
                    ];
                    
                    foreach ($commonPaths as $path) {
                        $eventLogger->log('debug', "[DEBUG] api/cron.php: Checking common path: {$path}", $userId);
                        if (file_exists($path)) {
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Common path exists: {$path}", $userId);
                            if (is_executable($path)) {
                                $eventLogger->log('debug', "[DEBUG] api/cron.php: Common path is executable: {$path}", $userId);
                                // Verify it's CLI by checking version output
                                $testOutput = shell_exec(escapeshellarg($path) . ' -v 2>&1');
                                if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                                    strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                                    $phpBinary = $path;
                                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP at common path: {$phpBinary}", $userId);
                                    $needsFpmDetection = false;
                                    break;
                                }
                            } else {
                                $eventLogger->log('warning', "[DEBUG] api/cron.php: Common path exists but not executable: {$path}", $userId);
                            }
                        }
                    }
                }
                
                // Method 5: Last resort - try 'php' command directly (rely on PATH)
                if ($needsFpmDetection) {
                    $eventLogger->log('debug', "[DEBUG] api/cron.php: Trying 'php' command directly (relying on PATH)", $userId);
                    $testOutput = shell_exec('php -v 2>&1');
                    if ($testOutput && strpos($testOutput, 'PHP') !== false && 
                        strpos($testOutput, 'fpm') === false && strpos($testOutput, 'FastCGI') === false) {
                        // Resolve the actual path of 'php' command
                        $resolvedPath = trim(shell_exec('command -v php 2>/dev/null'));
                        if (empty($resolvedPath)) {
                            $resolvedPath = trim(shell_exec('which php 2>/dev/null'));
                        }
                        if (!empty($resolvedPath) && file_exists($resolvedPath) && is_executable($resolvedPath)) {
                            $phpBinary = $resolvedPath;
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Found CLI PHP via 'php' command, resolved to: {$phpBinary}", $userId);
                            $needsFpmDetection = false;
                        } else {
                            // Fallback: use 'php' as-is (might work in some environments)
                            $phpBinary = 'php';
                            $eventLogger->log('debug', "[DEBUG] api/cron.php: Using 'php' command directly (could not resolve path)", $userId);
                            $needsFpmDetection = false;
                        }
                    } else {
                        $eventLogger->log('warning', "[DEBUG] api/cron.php: 'php' command test output: " . substr($testOutput, 0, 100), $userId);
                    }
                }
            }
        } else {
            // PHP_BINARY appears to be CLI already - log for debugging but don't change it
            $eventLogger->log('debug', "[DEBUG] api/cron.php: PHP_BINARY appears to be CLI: {$phpBinary}", $userId);
        }
        
        $eventLogger->log('debug', "[DEBUG] api/cron.php: Cron script path: {$cronScript}", $userId);
        $eventLogger->log('debug', "[DEBUG] api/cron.php: PHP binary: {$phpBinary}", $userId);
        $eventLogger->log('debug', "[DEBUG] api/cron.php: PHP_BINARY constant: " . PHP_BINARY, $userId);
        $eventLogger->log('debug', "[DEBUG] api/cron.php: needsFpmDetection: " . ($needsFpmDetection ? 'true' : 'false'), $userId);
        
        // If we detected FPM but couldn't find a CLI replacement, fail with clear error
        // This ensures we don't try to execute with FPM binary
        if ($needsFpmDetection) {
            $errorMsg = "Detected PHP-FPM binary but could not find CLI PHP replacement. Original: " . PHP_BINARY;
            if ($phpBinary !== PHP_BINARY) {
                $errorMsg .= ", Attempted: " . $phpBinary;
            }
            error_log($errorMsg);
            $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not find PHP CLI binary. Please configure cron to use CLI PHP directly.']);
            exit(1);
        }
        
        // Final validation: Ensure PHP binary exists and is executable
        // If phpBinary is 'php' (command), skip file checks as it will be resolved at execution time
        if ($phpBinary !== 'php') {
            if (!file_exists($phpBinary)) {
                $errorMsg = "PHP binary not found: {$phpBinary}";
                error_log($errorMsg);
                $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'PHP CLI binary not found']);
                exit(1);
            }
            
            if (!is_executable($phpBinary)) {
                $errorMsg = "PHP binary not executable: {$phpBinary}";
                error_log($errorMsg);
                $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'PHP CLI binary is not executable']);
                exit(1);
            }
        } else {
            // Validate 'php' command works
            $testOutput = shell_exec('php -v 2>&1');
            if (empty($testOutput) || strpos($testOutput, 'PHP') === false) {
                $errorMsg = "PHP command 'php' is not available or invalid";
                error_log($errorMsg);
                $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'PHP CLI command not available']);
                exit(1);
            }
        }
        
        // If we replaced the binary, do a final validation to ensure it's CLI
        // (We already validated during detection, but this is a safety check)
        if ($phpBinary !== PHP_BINARY) {
            // For -r flag, don't include <?php tag - just the code
            $testScript = "echo 'CLI_OK';";
            // Build command differently for 'php' command vs full path
            if ($phpBinary === 'php') {
                $command = 'php -r ' . escapeshellarg($testScript) . ' 2>&1';
            } else {
                $command = escapeshellarg($phpBinary) . ' -r ' . escapeshellarg($testScript) . ' 2>&1';
            }
            $testResult = shell_exec($command);
            if (trim($testResult) !== 'CLI_OK') {
                $errorMsg = "Replacement PHP binary validation failed. Test result: " . substr($testResult, 0, 200);
                error_log($errorMsg);
                $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
                $eventLogger->log('debug', "[DEBUG] api/cron.php: Command used: {$command}", $userId);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Invalid PHP CLI binary detected']);
                exit(1);
            }
            $eventLogger->log('debug', "[DEBUG] api/cron.php: Validated replacement CLI PHP binary successfully", $userId);
        }
        
        // Ensure script exists
        if (!file_exists($cronScript)) {
            $errorMsg = "Cron script not found: {$cronScript}";
            error_log($errorMsg);
            $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Cron script not found']);
            exit(1);
        }
        
        // Build command - execute exactly as cron would
        // Use absolute path and ensure we're in the right directory
        $cronScript = realpath($cronScript);
        $workingDir = dirname($cronScript);
        
        // Use data directory for log file (should be writable)
        $dataDir = $workingDir . '/data';
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        $logFile = $dataDir . '/notify-cron.log';
        
        // Ensure log file is writable, create if doesn't exist
        if (!file_exists($logFile)) {
            @touch($logFile);
            @chmod($logFile, 0644);
        }
        
        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($cronScript) . ' 2>&1';
        
        // Log the command for debugging
        error_log("Executing cron command: {$command} in directory: {$workingDir}");
        $eventLogger->log('debug', "[DEBUG] api/cron.php: Command: {$command}", $userId);
        $eventLogger->log('debug', "[DEBUG] api/cron.php: Working directory: {$workingDir}", $userId);
        $eventLogger->log('debug', "[DEBUG] api/cron.php: Log file: {$logFile}", $userId);
        
        // Execute in background (non-blocking)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            $handle = popen("start /B " . $command, "r");
            if ($handle === false) {
                error_log("Failed to start cron script on Windows");
                $eventLogger->log('error', "[DEBUG] api/cron.php: Failed to start cron script on Windows", $userId);
            } else {
                pclose($handle);
                $eventLogger->log('info', "[DEBUG] api/cron.php: Cron script started successfully (Windows)", $userId);
            }
        } else {
            // Unix/Linux - use nohup to ensure it continues after parent exits
            // Change to script directory and execute
            // Use full path to nohup if available, otherwise just run in background
            $nohupPath = trim(shell_exec('which nohup 2>/dev/null') ?: 'nohup');
            
            // Try with nohup first, redirecting to data directory log file
            $fullCommand = "cd " . escapeshellarg($workingDir) . " && " . escapeshellarg($nohupPath) . " " . $command . " >> " . escapeshellarg($logFile) . " 2>&1 & echo $!";
            
            $pid = trim(shell_exec($fullCommand));
            $eventLogger->log('debug', "[DEBUG] api/cron.php: Shell exec returned PID: '{$pid}'", $userId);
            
            if (empty($pid) || !is_numeric($pid)) {
                $eventLogger->log('warning', "[DEBUG] api/cron.php: PID not numeric, trying fallback method", $userId);
                // Fallback: try without nohup, but still use data directory for log
                $fallbackCommand = "cd " . escapeshellarg($workingDir) . " && " . $command . " >> " . escapeshellarg($logFile) . " 2>&1 &";
                $eventLogger->log('debug', "[DEBUG] api/cron.php: Fallback command: {$fallbackCommand}", $userId);
                exec($fallbackCommand, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    $errorMsg = "Failed to execute cron script. Return code: {$returnVar}, Output: " . implode("\n", $output);
                    error_log($errorMsg);
                    $eventLogger->log('error', "[DEBUG] api/cron.php: {$errorMsg}", $userId);
                } else {
                    $successMsg = "Cron script started successfully (fallback method)";
                    error_log($successMsg);
                    $eventLogger->log('info', "[DEBUG] api/cron.php: {$successMsg}", $userId);
                }
            } else {
                $successMsg = "Cron script started successfully with PID: {$pid}";
                error_log($successMsg);
                $eventLogger->log('info', "[DEBUG] api/cron.php: {$successMsg}", $userId);
                
                // Verify the process is actually running
                sleep(1); // Wait a moment for process to start
                $checkPid = trim(shell_exec("ps -p {$pid} -o pid= 2>/dev/null"));
                if ($checkPid) {
                    $eventLogger->log('info', "[DEBUG] api/cron.php: Verified process {$pid} is running", $userId);
                } else {
                    $eventLogger->log('warning', "[DEBUG] api/cron.php: Process {$pid} not found - may have exited immediately", $userId);
                    // Check the log file for errors
                    if (file_exists($logFile) && is_readable($logFile)) {
                        $lastLines = trim(shell_exec("tail -20 " . escapeshellarg($logFile) . " 2>/dev/null"));
                        if ($lastLines) {
                            $eventLogger->log('error', "[DEBUG] api/cron.php: Last log entries: " . substr($lastLines, 0, 500), $userId);
                        }
                    } else {
                        $eventLogger->log('warning', "[DEBUG] api/cron.php: Log file not readable: {$logFile}", $userId);
                    }
                }
            }
        }
        
        // Don't return anything - request already finished
        exit(0);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    error_log("api/cron.php Exception: {$errorMsg}");
    if (isset($eventLogger)) {
        $eventLogger->log('error', "[DEBUG] api/cron.php: Exception: {$errorMsg}", $userId);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $errorMsg]);
}

