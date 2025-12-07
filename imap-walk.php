#!/usr/bin/env php
<?php
/**
 * IMAP Walk Script
 * 
 * This script walks through all IMAP mailboxes configured in the database,
 * lists all folders, and displays message headers for debugging purposes.
 * 
 * Usage: 
 *   php imap-walk.php              # Scan all mailboxes
 *   php imap-walk.php 1             # Scan only mailbox ID 1
 * 
 * Output includes:
 *   - Connection status for each mailbox
 *   - List of all folders with message counts
 *   - Sample message headers (first 5 and last 5 messages per folder)
 *   - Detection of bounce messages
 *   - Folder status (total, unseen, recent messages)
 * 
 * This is useful for:
 *   - Debugging IMAP connection issues
 *   - Verifying folder structure
 *   - Checking message accessibility
 *   - Identifying bounce messages
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use BounceNG\Database;

// Set time limit for long-running operations
set_time_limit(600); // 10 minutes

// Colors for terminal output (if supported)
define('COLOR_RESET', "\033[0m");
define('COLOR_BOLD', "\033[1m");
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_CYAN', "\033[36m");

function printHeader($text) {
    echo "\n" . COLOR_BOLD . COLOR_CYAN . str_repeat("=", 80) . COLOR_RESET . "\n";
    echo COLOR_BOLD . COLOR_CYAN . $text . COLOR_RESET . "\n";
    echo COLOR_BOLD . COLOR_CYAN . str_repeat("=", 80) . COLOR_RESET . "\n\n";
}

function printSection($text) {
    echo "\n" . COLOR_BOLD . COLOR_BLUE . ">>> " . $text . COLOR_RESET . "\n";
}

function printSuccess($text) {
    echo COLOR_GREEN . "✓ " . $text . COLOR_RESET . "\n";
}

function printWarning($text) {
    echo COLOR_YELLOW . "⚠ " . $text . COLOR_RESET . "\n";
}

function printError($text) {
    echo COLOR_RED . "✗ " . $text . COLOR_RESET . "\n";
}

function printInfo($text) {
    echo "  " . $text . "\n";
}

try {
    $db = Database::getInstance();
    
    // Get mailbox ID from command line argument
    $mailboxId = isset($argv[1]) ? intval($argv[1]) : null;
    
    if ($mailboxId) {
        // Scan specific mailbox
        $stmt = $db->prepare("SELECT * FROM mailboxes WHERE id = ?");
        $stmt->execute([$mailboxId]);
        $mailboxes = $stmt->fetchAll();
        
        if (empty($mailboxes)) {
            printError("Mailbox ID {$mailboxId} not found in database.");
            exit(1);
        }
    } else {
        // Scan all mailboxes
        $stmt = $db->query("SELECT * FROM mailboxes ORDER BY id");
        $mailboxes = $stmt->fetchAll();
        
        if (empty($mailboxes)) {
            printError("No mailboxes found in database.");
            exit(1);
        }
    }
    
    printHeader("IMAP Mailbox Walker - Scanning " . count($mailboxes) . " mailbox(es)");
    
    foreach ($mailboxes as $mailbox) {
        printHeader("Mailbox: {$mailbox['name']} (ID: {$mailbox['id']})");
        
        printInfo("Server: {$mailbox['imap_server']}:{$mailbox['imap_port']}");
        printInfo("Protocol: {$mailbox['imap_protocol']}");
        printInfo("Username: {$mailbox['imap_username']}");
        printInfo("Email: {$mailbox['email']}");
        printInfo("Configured Folders:");
        printInfo("  - Inbox: {$mailbox['folder_inbox']}");
        printInfo("  - Processed: {$mailbox['folder_processed']}");
        printInfo("  - Problem: {$mailbox['folder_problem']}");
        printInfo("  - Skipped: {$mailbox['folder_skipped']}");
        printInfo("Last Processed: " . ($mailbox['last_processed'] ?: 'Never'));
        
        // Build connection string
        $server = $mailbox['imap_server'];
        $port = $mailbox['imap_port'];
        $protocol = strtolower($mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";
        
        printSection("Connecting to IMAP server...");
        
        // Connect to IMAP
        $imapConnection = @imap_open(
            $connectionString,
            $mailbox['imap_username'],
            $mailbox['imap_password']
        );
        
        if (!$imapConnection) {
            $error = imap_last_error();
            printError("Failed to connect: {$error}");
            continue;
        }
        
        printSuccess("Connected successfully");
        
        // Get all mailboxes/folders
        printSection("Listing all folders...");
        
        $allMailboxes = @imap_list($imapConnection, $connectionString, "*");
        
        if (!$allMailboxes) {
            $error = imap_last_error();
            printError("Failed to list folders: {$error}");
            imap_close($imapConnection);
            continue;
        }
        
        $folders = [];
        foreach ($allMailboxes as $mb) {
            $folderName = imap_utf7_decode(str_replace($connectionString, '', $mb));
            $folders[] = [
                'name' => $folderName,
                'path' => $mb
            ];
        }
        
        // Sort folders alphabetically
        usort($folders, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        printSuccess("Found " . count($folders) . " folder(s)");
        
        // Walk through each folder
        foreach ($folders as $folder) {
            $folderName = $folder['name'];
            $folderPath = $folder['path'];
            
            printSection("Folder: {$folderName}");
            
            // Get folder status
            $status = @imap_status($imapConnection, $folderPath, SA_MESSAGES | SA_UNSEEN | SA_RECENT);
            
            if (!$status) {
                printWarning("Could not get status for folder");
                continue;
            }
            
            $messageCount = $status->messages ?? 0;
            $unseenCount = $status->unseen ?? 0;
            $recentCount = $status->recent ?? 0;
            
            printInfo("Total Messages: {$messageCount}");
            printInfo("Unseen: {$unseenCount}");
            printInfo("Recent: {$recentCount}");
            
            if ($messageCount == 0) {
                printInfo("(No messages in this folder)");
                continue;
            }
            
            // Select the folder
            $selected = @imap_reopen($imapConnection, $folderPath);
            if (!$selected) {
                printWarning("Could not select folder: " . imap_last_error());
                continue;
            }
            
            // Get message UIDs
            $uids = @imap_search($imapConnection, 'ALL', SE_UID);
            $usingUids = true;
            
            if (!$uids || empty($uids)) {
                // Try without SE_UID
                $uids = @imap_search($imapConnection, 'ALL');
                $usingUids = false;
            }
            
            if (!$uids || empty($uids)) {
                printWarning("Could not fetch message list");
                continue;
            }
            
            // Sort UIDs in reverse order (newest first)
            rsort($uids);
            
            // Show sample headers (first 5 and last 5 messages)
            $sampleCount = min(5, count($uids));
            $samples = array_slice($uids, 0, $sampleCount);
            
            if (count($uids) > 10) {
                $samples = array_merge($samples, array_slice($uids, -5));
            }
            
            printInfo("\nSample Message Headers (showing " . count($samples) . " of {$messageCount}):");
            printInfo(str_repeat("-", 80));
            
            foreach ($samples as $index => $uid) {
                $imapFlag = $usingUids ? FT_UID : 0;
                
                // Get message header
                $header = @imap_fetchheader($imapConnection, $uid, $imapFlag);
                
                if (!$header) {
                    printWarning("  Message " . ($usingUids ? "UID" : "#") . " {$uid}: Could not fetch header");
                    continue;
                }
                
                // Parse key headers
                $subject = '';
                $from = '';
                $date = '';
                $to = '';
                $cc = '';
                
                if (preg_match('/^Subject:\s*(.+)$/mi', $header, $matches)) {
                    $subject = trim($matches[1]);
                    // Decode if encoded
                    $decoded = @imap_mime_header_decode($subject);
                    if (is_array($decoded) && !empty($decoded)) {
                        $subject = '';
                        foreach ($decoded as $part) {
                            $subject .= $part->text;
                        }
                    }
                }
                
                if (preg_match('/^From:\s*(.+)$/mi', $header, $matches)) {
                    $from = trim($matches[1]);
                }
                
                if (preg_match('/^Date:\s*(.+)$/mi', $header, $matches)) {
                    $date = trim($matches[1]);
                }
                
                if (preg_match('/^To:\s*(.+)$/mi', $header, $matches)) {
                    $to = trim($matches[1]);
                }
                
                if (preg_match('/^Cc:\s*(.+)$/mi', $header, $matches)) {
                    $cc = trim($matches[1]);
                }
                
                // Truncate long values
                $subject = strlen($subject) > 60 ? substr($subject, 0, 57) . '...' : $subject;
                $from = strlen($from) > 50 ? substr($from, 0, 47) . '...' : $from;
                
                $msgNum = $index + 1;
                $total = count($samples);
                $isLast = ($index == count($samples) - 1);
                
                if ($index == $sampleCount && count($uids) > 10) {
                    printInfo("  ... (showing last 5 messages) ...");
                }
                
                printInfo("  [{$msgNum}/{$total}] " . ($usingUids ? "UID" : "Msg") . ": {$uid}");
                if ($subject) printInfo("      Subject: {$subject}");
                if ($from) printInfo("      From: {$from}");
                if ($date) printInfo("      Date: {$date}");
                if ($to) printInfo("      To: {$to}");
                if ($cc) printInfo("      Cc: {$cc}");
                
                // Check if this looks like a bounce
                $isBounce = false;
                if (stripos($subject, 'undeliverable') !== false ||
                    stripos($subject, 'bounce') !== false ||
                    stripos($subject, 'delivery failure') !== false ||
                    stripos($subject, 'mail delivery failed') !== false ||
                    stripos($header, 'X-Failed-Recipients') !== false ||
                    stripos($header, 'Return-Path: <>') !== false) {
                    $isBounce = true;
                    printInfo("      " . COLOR_YELLOW . "→ Looks like a BOUNCE" . COLOR_RESET);
                }
                
                if (!$isLast) {
                    printInfo("");
                }
            }
            
            printInfo(str_repeat("-", 80));
        }
        
        // Close connection
        imap_close($imapConnection);
        printSuccess("Disconnected from mailbox");
    }
    
    printHeader("Scan Complete");
    printSuccess("Finished scanning all mailboxes");
    
} catch (Exception $e) {
    printError("Fatal error: " . $e->getMessage());
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

