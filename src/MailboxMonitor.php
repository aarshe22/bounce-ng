<?php

namespace BounceNG;

use Exception;

class MailboxMonitor {
    private $db;
    private $mailbox;
    private $imapConnection;
    private $eventLogger;

    public function __construct($mailboxId) {
        $this->db = Database::getInstance();
        $this->eventLogger = new EventLogger();
        $this->loadMailbox($mailboxId);
    }

    private function loadMailbox($mailboxId) {
        $stmt = $this->db->prepare("SELECT * FROM mailboxes WHERE id = ?");
        $stmt->execute([$mailboxId]);
        $this->mailbox = $stmt->fetch();

        if (!$this->mailbox) {
            throw new Exception("Mailbox not found");
        }
    }

    public function connect() {
        $server = $this->mailbox['imap_server'];
        $port = $this->mailbox['imap_port'];
        $protocol = strtolower($this->mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";

        $this->imapConnection = @\imap_open(
            $connectionString,
            $this->mailbox['imap_username'],
            $this->mailbox['imap_password']
        );

        if (!$this->imapConnection) {
            $error = \imap_last_error();
            $this->eventLogger->log('error', "Failed to connect to mailbox: {$error}", null, $this->mailbox['id']);
            throw new Exception("IMAP connection failed: {$error}");
        }

        $this->eventLogger->log('info', "Connected to mailbox: {$this->mailbox['name']}", null, $this->mailbox['id']);
    }

    public function getFolders() {
        if (!$this->imapConnection) {
            $this->connect();
        }

        $folders = [];
        // Build connection string for listing
        $server = $this->mailbox['imap_server'];
        $port = $this->mailbox['imap_port'];
        $protocol = strtolower($this->mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";
        
        $mailboxes = @\imap_list($this->imapConnection, $connectionString, "*");
        
        if ($mailboxes) {
            foreach ($mailboxes as $mailbox) {
                // Extract folder name from full mailbox path
                $folder = str_replace($connectionString, "", $mailbox);
                $folder = \imap_utf7_decode($folder);
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    public function processInbox() {
        // Verify database schema on each run
        try {
            $this->db->verifySchema();
        } catch (\Exception $e) {
            $this->eventLogger->log('warning', "Database schema verification failed: " . $e->getMessage(), null, $this->mailbox['id']);
            error_log("MailboxMonitor: Database schema verification failed: " . $e->getMessage());
        }
        
        $this->eventLogger->log('debug', "=== processInbox() START ===", null, $this->mailbox['id']);
        error_log("MailboxMonitor: === processInbox() START ===");
        
        $inbox = $this->mailbox['folder_inbox'];
        $processed = $this->mailbox['folder_processed'];
        $problem = $this->mailbox['folder_problem'];
        $skipped = $this->mailbox['folder_skipped'];
        
        $this->eventLogger->log('debug', "processInbox() - inbox='{$inbox}', processed='{$processed}', problem='{$problem}', skipped='{$skipped}'", null, $this->mailbox['id']);
        error_log("MailboxMonitor: processInbox() - inbox='{$inbox}', processed='{$processed}', problem='{$problem}', skipped='{$skipped}'");

        // Build connection string for folder selection
        $server = $this->mailbox['imap_server'];
        $port = $this->mailbox['imap_port'];
        $protocol = strtolower($this->mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";
        
        // Close existing connection if any
        if ($this->imapConnection) {
            @\imap_close($this->imapConnection);
            $this->imapConnection = null;
        }
        
        // First connect to server root
        $this->imapConnection = @\imap_open(
            $connectionString,
            $this->mailbox['imap_username'],
            $this->mailbox['imap_password']
        );
        
        if (!$this->imapConnection) {
            $error = \imap_last_error();
            $this->eventLogger->log('error', "Failed to connect to IMAP server: {$error}", null, $this->mailbox['id']);
            throw new Exception("IMAP connection failed: {$error}");
        }
        
        // Get the actual mailbox path from imap_list (more reliable than constructing it)
        // Use comprehensive folder matching to support custom folders
        $allMailboxes = @\imap_list($this->imapConnection, $connectionString, "*");
        $actualMailboxPath = null;
        
        // CRITICAL: Get the actual connection string that imap_list uses
        // imap_list returns paths that include the full connection string with authentication
        // We need to extract the base connection string from the first mailbox path
        // Format from imap_list: {server:port/imap/user="user"}FOLDER
        $baseConnectionString = $connectionString;
        if ($allMailboxes && count($allMailboxes) > 0) {
            // Extract the connection string part from the first mailbox path
            $firstMailbox = $allMailboxes[0];
            // Find the LAST closing brace (there may be multiple if folder names contain braces)
            // The connection string ends with the brace before the folder name
            $bracePos = strpos($firstMailbox, '}');
            if ($bracePos !== false) {
                // Look for the pattern: }FOLDER (where FOLDER doesn't start with another {)
                // Actually, the connection string is everything up to and including the first }
                // that's followed by a non-brace character (the folder name)
                $baseConnectionString = substr($firstMailbox, 0, $bracePos + 1);
                $this->eventLogger->log('debug', "Extracted base connection string from imap_list: '{$baseConnectionString}' (from: '{$firstMailbox}')", null, $this->mailbox['id']);
            } else {
                // Fallback: if no brace found, try to find where the folder name starts
                // This shouldn't happen with proper IMAP paths, but handle it anyway
                $this->eventLogger->log('warning', "Could not extract base connection string from mailbox path: '{$firstMailbox}'", null, $this->mailbox['id']);
            }
        }
        
        // Try multiple folder name variations to handle case sensitivity, encoding, and custom folders
        $folderVariations = [
            $inbox,
            strtoupper($inbox),
            strtolower($inbox),
            ucfirst(strtolower($inbox)),
            'INBOX' . ($inbox !== 'INBOX' ? '/' . $inbox : ''),
            // For custom folders, also try without INBOX prefix
            str_replace('INBOX/', '', $inbox),
            str_replace('INBOX\\', '', $inbox),
        ];
        
        // Remove duplicates and empty values
        $folderVariations = array_unique(array_filter($folderVariations));
        
        $this->eventLogger->log('debug', "Looking for inbox folder: '{$inbox}'. Trying variations: " . implode(', ', $folderVariations), null, $this->mailbox['id']);
        
        // Find the actual mailbox path that matches our folder name
        if ($allMailboxes) {
            foreach ($allMailboxes as $mb) {
                // Use the extracted base connection string to get the folder name
                $folder = str_replace($baseConnectionString, "", $mb);
                $folderDecoded = \imap_utf7_decode($folder);
                
                // Try exact match first
                if (strcasecmp($folderDecoded, $inbox) === 0 || $folderDecoded === $inbox) {
                    $actualMailboxPath = $mb; // Use the full mailbox path from imap_list
                    $this->eventLogger->log('info', "Found matching inbox folder (exact): '{$folderDecoded}' -> '{$mb}'", null, $this->mailbox['id']);
                    break;
                }
                
                // Try all variations
                foreach ($folderVariations as $var) {
                    if (strcasecmp($folderDecoded, $var) === 0 || $folderDecoded === $var) {
                        $actualMailboxPath = $mb; // Use the full mailbox path from imap_list
                        $this->eventLogger->log('info', "Found matching inbox folder: '{$folderDecoded}' -> '{$mb}' (matched variation: '{$var}')", null, $this->mailbox['id']);
                        break 2;
                    }
                }
            }
        }
        
        if (!$actualMailboxPath) {
            // List available folders for error message
            $availableFolders = [];
            if ($allMailboxes) {
                foreach ($allMailboxes as $mb) {
                    $folder = str_replace($baseConnectionString, "", $mb);
                    $folder = \imap_utf7_decode($folder);
                    $availableFolders[] = $folder;
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('error', "Inbox folder '{$inbox}' not found. Available folders: {$foldersList}", null, $this->mailbox['id']);
            throw new Exception("Inbox folder '{$inbox}' not found. Available folders: {$foldersList}");
        }
        
        // Folder is already selected from the code above, just verify
        $this->eventLogger->log('info', "Folder selected: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // DIAGNOSTIC: Check what the server reports BEFORE we start fetching
        $checkResult = @\imap_check($this->imapConnection);
        $status = @\imap_status($this->imapConnection, $actualMailboxPath, SA_ALL);
        $numMsg = @\imap_num_msg($this->imapConnection);
        
        $this->eventLogger->log('info', "IMAP diagnostics - Selected path: '{$actualMailboxPath}' | imap_check: " . ($checkResult ? "OK (Mailbox: {$checkResult->Mailbox}, Messages: {$checkResult->Nmsgs})" : "FAILED") . 
            " | imap_status: " . ($status ? "Messages: {$status->messages}, Recent: {$status->recent}, Unseen: {$status->unseen}" : "FAILED") . 
            " | imap_num_msg: " . ($numMsg !== false ? $numMsg : "FAILED"), null, $this->mailbox['id']);
        
        // CRITICAL: Always use the path from imap_check - it's the authoritative source
        // imap_list returns paths without auth, but imap_check shows the real path with auth
        if ($checkResult && isset($checkResult->Mailbox)) {
            if ($checkResult->Mailbox !== $actualMailboxPath) {
                $this->eventLogger->log('info', "Updating mailbox path from imap_check: '{$actualMailboxPath}' -> '{$checkResult->Mailbox}'", null, $this->mailbox['id']);
                $actualMailboxPath = $checkResult->Mailbox;
                // Make sure we're actually in this folder
                $reopenResult = @\imap_reopen($this->imapConnection, $actualMailboxPath);
                if ($reopenResult) {
                    // Re-check after reopening
                    $checkResult = @\imap_check($this->imapConnection);
                    $status = @\imap_status($this->imapConnection, $actualMailboxPath, SA_ALL);
                    $numMsg = @\imap_num_msg($this->imapConnection);
                    $this->eventLogger->log('info', "After reopening to correct path - imap_check: " . ($checkResult ? "OK (Mailbox: {$checkResult->Mailbox}, Messages: {$checkResult->Nmsgs})" : "FAILED") . 
                        " | imap_status: " . ($status ? "Messages: {$status->messages}, Recent: {$status->recent}, Unseen: {$status->unseen}" : "FAILED") . 
                        " | imap_num_msg: " . ($numMsg !== false ? $numMsg : "FAILED"), null, $this->mailbox['id']);
                } else {
                    $this->eventLogger->log('error', "Failed to reopen to correct folder: " . (\imap_last_error() ?: 'unknown error'), null, $this->mailbox['id']);
                }
            }
        }
        
        // DIAGNOSTIC: List all folders and their message counts to help debug
        if ($allMailboxes && count($allMailboxes) > 0) {
            $this->eventLogger->log('debug', "Listing all folders and their message counts:", null, $this->mailbox['id']);
            foreach ($allMailboxes as $mb) {
                $folder = str_replace($baseConnectionString, "", $mb);
                $folderDecoded = \imap_utf7_decode($folder);
                // Try to get status for this folder
                $folderStatus = @\imap_status($this->imapConnection, $mb, SA_MESSAGES | SA_UNSEEN);
                if ($folderStatus) {
                    $this->eventLogger->log('debug', "  Folder: '{$folderDecoded}' -> Messages: {$folderStatus->messages}, Unseen: {$folderStatus->unseen}", null, $this->mailbox['id']);
                } else {
                    $this->eventLogger->log('debug', "  Folder: '{$folderDecoded}' -> Status check failed", null, $this->mailbox['id']);
                }
            }
        }
        
        // SEQUENTIAL FETCH AS PRIMARY METHOD - Most reliable way to get ALL messages
        // Don't trust imap_search - it may filter or miss messages
        // Start from message 1 and keep going until we can't fetch anymore
        $this->eventLogger->log('info', "Fetching ALL messages using sequential method (starting from message 1)...", null, $this->mailbox['id']);
        
        $uids = [];
        $usingUids = false; // Using message numbers for sequential fetch
        
        // Clear any previous IMAP errors
        @\imap_errors();
        
        // Sequential fetch: start from 1 and keep going until we can't fetch anymore
        // This is the most reliable method - it gets EVERYTHING regardless of status
        // Use a smarter approach: keep going until we hit a long gap of missing messages
        $maxMessages = 50000; // Reasonable upper limit
        $consecutiveFailures = 0;
        $maxConsecutiveFailures = 50; // Stop after 50 consecutive failures (handles gaps)
        $lastFoundMessage = 0;
        
        // If server reports messages exist, be more persistent
        $serverReportsMessages = false;
        $reportedCount = 0;
        if ($checkResult && isset($checkResult->Nmsgs) && $checkResult->Nmsgs > 0) {
            $serverReportsMessages = true;
            $reportedCount = $checkResult->Nmsgs;
        } elseif ($status && isset($status->messages) && $status->messages > 0) {
            $serverReportsMessages = true;
            $reportedCount = $status->messages;
        } elseif ($numMsg && $numMsg > 0) {
            $serverReportsMessages = true;
            $reportedCount = $numMsg;
        }
        
        if ($serverReportsMessages) {
            $this->eventLogger->log('info', "Server reports {$reportedCount} messages exist - will search aggressively up to message " . ($reportedCount * 2), null, $this->mailbox['id']);
            // If server says messages exist, search at least up to 2x the reported count
            $maxMessages = max($maxMessages, $reportedCount * 2);
        }
        
        for ($msgNum = 1; $msgNum <= $maxMessages; $msgNum++) {
            // Try to fetch the header - if it exists, we have a message
            $testHeader = @\imap_fetchheader($this->imapConnection, $msgNum);
            $lastError = @\imap_last_error();
            
            if ($testHeader && !empty(trim($testHeader))) {
                $uids[] = $msgNum;
                $lastFoundMessage = $msgNum;
                $consecutiveFailures = 0; // Reset failure counter
                
                // Log first message found
                if (count($uids) == 1) {
                    $this->eventLogger->log('info', "✓ Found first message at number {$msgNum}", null, $this->mailbox['id']);
                }
                
                // Log progress every 25 messages for visibility
                if (count($uids) % 25 == 0) {
                    $this->eventLogger->log('info', "Sequential fetch: found " . count($uids) . " messages so far (currently at message {$msgNum})...", null, $this->mailbox['id']);
                }
            } else {
                $consecutiveFailures++;
                
                // Only stop if:
                // 1. We've found at least one message (so we know messages exist)
                // 2. We've hit the max consecutive failures (we're past the end)
                // 3. We're far enough past the last found message (safety check)
                if (count($uids) > 0 && $consecutiveFailures >= $maxConsecutiveFailures) {
                    $this->eventLogger->log('info', "Reached end of messages after {$consecutiveFailures} consecutive failures (last found: {$lastFoundMessage}). Found " . count($uids) . " total messages.", null, $this->mailbox['id']);
                    break;
                }
                
                // If we haven't found any messages yet, keep trying longer
                // CRITICAL: Don't trust imap_status - it may report 0 even when messages exist
                // Some IMAP servers have issues with message counting, especially for unread messages
                // We'll search aggressively regardless of what the server reports
                if (count($uids) == 0) {
                    // Always be persistent - search up to a reasonable limit
                    // Even if server says 0 messages, messages might exist
                    if ($msgNum == 200) {
                        $this->eventLogger->log('info', "Searched 200 messages with no results, but continuing search (server may report incorrect counts)...", null, $this->mailbox['id']);
                    }
                    
                    if ($msgNum == 500) {
                        $this->eventLogger->log('info', "Searched 500 messages with no results, but continuing search...", null, $this->mailbox['id']);
                    }
                    
                    // Search up to 2000 messages before giving up
                    // This handles cases where server reports 0 but messages actually exist
                    if ($msgNum >= 2000) {
                        $this->eventLogger->log('warning', "No messages found after 2000 attempts. Stopping sequential fetch.", null, $this->mailbox['id']);
                        break;
                    }
                    
                    // If server reports messages exist, search even more aggressively
                    if ($serverReportsMessages && $msgNum > $reportedCount * 3) {
                        $this->eventLogger->log('info', "Server reports {$reportedCount} messages but searched up to " . ($reportedCount * 3) . ". Continuing search...", null, $this->mailbox['id']);
                        // Continue - don't give up yet
                    }
                }
            }
        }
        
        if (empty($uids)) {
            $this->eventLogger->log('warning', "Sequential fetch found no messages in folder '{$inbox}'. Folder may be empty.", null, $this->mailbox['id']);
            return [
                'processed' => 0,
                'skipped' => 0,
                'problems' => 0
            ];
        }
        
        $messageCount = count($uids);
        $methodDesc = $usingUids ? "UIDs" : "message numbers";
        $rangeDesc = !empty($uids) ? " (range: " . min($uids) . "-" . max($uids) . ")" : "";
        $this->eventLogger->log('info', "✓ Found {$messageCount} messages using {$methodDesc}{$rangeDesc}", null, $this->mailbox['id']);
        $this->eventLogger->log('info', "Processing {$messageCount} messages from folder '{$inbox}' (all messages, read and unread)", null, $this->mailbox['id']);

        $processedCount = 0;
        $skippedCount = 0;
        $problemCount = 0;

        // Verify we have a valid connection and folder is selected
        if (!$this->imapConnection) {
            $this->eventLogger->log('error', "IMAP connection lost before processing", null, $this->mailbox['id']);
            throw new Exception("IMAP connection lost before processing");
        }
        
        $this->eventLogger->log('info', "✓ Processing " . count($uids) . " messages using " . ($usingUids ? "UIDs" : "message numbers"), null, $this->mailbox['id']);
        
        // Process messages in reverse order to avoid number shifting issues
        $uids = array_reverse($uids);
        
        // Determine which flag to use for IMAP functions
        $imapFlag = $usingUids ? FT_UID : 0;
        
        $this->eventLogger->log('info', "✓ Starting message processing loop. Total messages: " . count($uids), null, $this->mailbox['id']);
        error_log("MailboxMonitor: ✓ Starting message processing loop. Total messages: " . count($uids));
        
        foreach ($uids as $index => $uid) {
            if (($index + 1) % 10 == 0 || $index == 0) {
                $this->eventLogger->log('info', "Processing message " . ($index + 1) . " of " . count($uids) . " (" . ($usingUids ? "UID" : "number") . ": {$uid})", null, $this->mailbox['id']);
            }
            $this->eventLogger->log('debug', "Processing message " . ($index + 1) . " of " . count($uids) . " (" . ($usingUids ? "UID" : "number") . ": {$uid})", null, $this->mailbox['id']);
            try {
                // Fetch message structure to understand MIME parts
                $structure = @\imap_fetchstructure($this->imapConnection, $uid, $imapFlag);
                if (!$structure) {
                    $this->eventLogger->log('warning', "Could not fetch structure for message " . ($usingUids ? "UID" : "number") . " {$uid}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                // Fetch headers
                $rawHeader = @\imap_fetchbody($this->imapConnection, $uid, '0', $imapFlag);
                if ($rawHeader === false || $rawHeader === '') {
                    $rawHeader = @\imap_fetchheader($this->imapConnection, $uid, $imapFlag);
                }
                
                if (!$rawHeader || $rawHeader === '') {
                    $this->eventLogger->log('warning', "Could not fetch header for message " . ($usingUids ? "UID" : "number") . " {$uid}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                // AGGRESSIVELY fetch ALL MIME parts recursively - this is critical for finding CC addresses
                $rawParts = ['0' => $rawHeader];
                $this->fetchAllMimeParts($this->imapConnection, $uid, $structure, $rawParts, '', $imapFlag);
                
                // Also get full body as fallback
                $fullBody = @\imap_body($this->imapConnection, $uid, $imapFlag) ?: '';
                if (!empty($fullBody)) {
                    $rawParts['FULL_BODY'] = $fullBody;
                }
                
                // Also try to fetch body parts using different methods to catch everything
                // Some IMAP servers structure messages differently
                for ($partNum = 1; $partNum <= 20; $partNum++) {
                    $partBody = @\imap_fetchbody($this->imapConnection, $uid, (string)$partNum, $imapFlag);
                    if ($partBody !== false && !empty(trim($partBody))) {
                        $rawParts["PART_{$partNum}"] = $partBody;
                    } else {
                        // If we've found some parts but this one is empty, we might be done
                        // But keep trying a few more in case of gaps
                        if (count($rawParts) > 2 && $partNum > 5) {
                            break;
                        }
                    }
                }
                
                // Decode ALL parts to searchable text - this is critical for CC extraction
                $decodedParts = [];
                $messageRfc822Parts = [];
                $allDecodedText = [];
                
                foreach ($rawParts as $partNum => $partContent) {
                    if (empty($partContent)) {
                        continue;
                    }
                    
                    // Try to decode this part if it looks encoded
                    $decoded = $this->decodePartForSearch($partContent);
                    if (!empty($decoded)) {
                        $decodedParts[$partNum] = $decoded;
                        $allDecodedText[] = $decoded;
                        
                        // Check if this is message/rfc822 (embedded email) - these are goldmines for CC addresses
                        if (preg_match('/Content-Type:\s*message\/rfc822/i', $decoded) ||
                            preg_match('/Content-Type:\s*message\/rfc822-headers/i', $decoded) ||
                            preg_match('/^From:\s*[^\r\n]+/im', $decoded)) {
                            $messageRfc822Parts[] = $decoded;
                        }
                    } else {
                        // Even if decoding fails, include raw content
                        $allDecodedText[] = $partContent;
                    }
                }
                
                // Prioritize message/rfc822 parts (embedded emails) as they contain original headers
                $combinedBody = '';
                if (!empty($messageRfc822Parts)) {
                    $combinedBody = implode("\r\n\r\n", $messageRfc822Parts);
                    $this->eventLogger->log('debug', "Found " . count($messageRfc822Parts) . " message/rfc822 parts (embedded emails) - these should contain original CC headers", null, $this->mailbox['id']);
                } else {
                    $combinedBody = implode("\r\n\r\n", array_filter($allDecodedText));
                }
                
                // Combine header with all body parts
                $rawEmail = $rawHeader . "\r\n\r\n" . $combinedBody;
                
                // CRITICAL: Include ALL decoded parts for comprehensive CC search
                // The parser will search through all of this content
                $allPartsText = implode("\r\n\r\n---PART_SEPARATOR---\r\n\r\n", array_filter($allDecodedText));
                $rawEmail = $rawEmail . "\r\n\r\n---ALL_DECODED_PARTS---\r\n\r\n" . $allPartsText;
                
                $this->eventLogger->log('debug', "Aggressively fetched " . count($rawParts) . " raw parts, decoded " . count($decodedParts) . " parts for CC extraction", null, $this->mailbox['id']);

                $parser = new EmailParser($rawEmail);
                
                $isBounce = $parser->isBounce();
                $this->eventLogger->log('debug', "Message " . ($index + 1) . " (UID: {$uid}) - isBounce: " . var_export($isBounce, true), null, $this->mailbox['id']);

                if (!$isBounce) {
                    // Not a bounce, move to skipped
                    $this->moveMessage($uid, $skipped, $usingUids);
                    $skippedCount++;
                    $this->eventLogger->log('info', "Message " . ($index + 1) . " (" . ($usingUids ? "UID" : "number") . ": {$uid}) is not a bounce, moved to skipped", null, $this->mailbox['id']);
                    continue;
                }

                $this->eventLogger->log('debug', "Message " . ($index + 1) . " (UID: {$uid}) is a bounce, extracting data...", null, $this->mailbox['id']);

                // Extract bounce data
                $originalTo = $parser->getOriginalTo();
                $originalCc = $parser->getOriginalCc();
                $recipientDomain = $parser->getRecipientDomain();
                
                $ccCount = is_array($originalCc) ? count($originalCc) : 0;
                $ccList = is_array($originalCc) && !empty($originalCc) ? implode(', ', $originalCc) : 'NONE';
                $this->eventLogger->log('debug', "Extracted bounce data - To: {$originalTo}, CC count: {$ccCount}, CC addresses: {$ccList}, Domain: {$recipientDomain}", null, $this->mailbox['id']);
                
                // If no CC found, log a sample of the email body to help debug
                if (empty($originalCc) || $ccCount === 0) {
                    $parsedData = $parser->getParsedData();
                    $bodySample = substr($parsedData['body'] ?? '', 0, 500);
                    $this->eventLogger->log('debug', "No CC addresses found. Body sample (first 500 chars): " . substr($bodySample, 0, 500), null, $this->mailbox['id']);
                }

                if (!$originalTo || !$recipientDomain) {
                    // Cannot parse, move to problem
                    $this->moveMessage($uid, $problem, $usingUids);
                    $problemCount++;
                    $this->eventLogger->log('warning', "Cannot parse message " . ($usingUids ? "UID" : "number") . " {$uid}: missing original_to or domain", null, $this->mailbox['id']);
                    continue;
                }

                // Store bounce record
                $bounceId = $this->storeBounce($parser, $recipientDomain);

                // Calculate and update trust score
                $trustCalculator = new TrustScoreCalculator();
                $bounceData = $parser->getParsedData();
                $trustScore = $trustCalculator->updateDomainTrustScore($recipientDomain, $bounceData);

                // Queue notifications
                // If no CC addresses found, try to extract from stored bounce record (in case it was stored as string)
                if (empty($originalCc)) {
                    $ccCheckStmt = $this->db->prepare("SELECT original_cc FROM bounces WHERE id = ?");
                    $ccCheckStmt->execute([$bounceId]);
                    $bounceRecord = $ccCheckStmt->fetch();
                    if ($bounceRecord && !empty($bounceRecord['original_cc'])) {
                        // Parse the stored CC string using the parser's method
                        $tempParser = new EmailParser('');
                        $reflection = new \ReflectionClass($tempParser);
                        $parseMethod = $reflection->getMethod('parseEmailList');
                        $parseMethod->setAccessible(true);
                        $originalCc = $parseMethod->invoke($tempParser, $bounceRecord['original_cc']);
                        $this->eventLogger->log('info', "Extracted CC addresses from stored bounce record: " . count($originalCc) . " addresses", null, $this->mailbox['id']);
                    }
                }
                
                $this->queueNotifications($bounceId, $originalCc);

                // Move to processed
                $this->moveMessage($uid, $processed, $usingUids);
                $processedCount++;

                $ccCount = is_array($originalCc) ? count($originalCc) : 0;
                $this->eventLogger->log('success', "✓ Processed bounce " . ($index + 1) . "/" . count($uids) . " for {$originalTo} (Domain: {$recipientDomain}, Trust: {$trustScore}, CC: {$ccCount} addresses)", null, $this->mailbox['id'], $bounceId);
                
                if (($processedCount % 10 == 0) || $processedCount == 1) {
                    $this->eventLogger->log('info', "Progress: {$processedCount} processed, {$skippedCount} skipped, {$problemCount} problems", null, $this->mailbox['id']);
                }

            } catch (\Exception $e) {
                // Error processing, move to problem
                $this->moveMessage($uid, $problem, $usingUids);
                $problemCount++;
                $this->eventLogger->log('error', "Error processing message " . ($usingUids ? "UID" : "number") . " {$uid}: {$e->getMessage()}", null, $this->mailbox['id']);
            }
        }

        // Update last processed time
        $stmt = $this->db->prepare("UPDATE mailboxes SET last_processed = CURRENT_TIMESTAMP WHERE id = ?");
        $params = [$this->mailbox['id']];
        $this->db->logSql($stmt->queryString, $params);
        $stmt->execute($params);

        $this->eventLogger->log('info', "✓✓✓ Processing complete: {$processedCount} processed, {$skippedCount} skipped, {$problemCount} problems", null, $this->mailbox['id']);
        error_log("MailboxMonitor: ✓✓✓ Processing complete: {$processedCount} processed, {$skippedCount} skipped, {$problemCount} problems");

        $this->eventLogger->log('debug', "=== processInbox() END - returning result ===", null, $this->mailbox['id']);
        error_log("MailboxMonitor: === processInbox() END - returning result ===");

        return [
            'processed' => $processedCount,
            'skipped' => $skippedCount,
            'problems' => $problemCount
        ];
    }

    private function storeBounce($parser, $recipientDomain) {
        $stmt = $this->db->prepare("
            INSERT INTO bounces (
                mailbox_id, original_to, original_cc, original_subject,
                original_sent_date, bounce_date, smtp_code, smtp_reason,
                recipient_domain, bounce_type, spam_score, deliverability_status,
                trust_score, raw_headers, raw_body
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $data = $parser->getParsedData();
        $originalCc = $parser->getOriginalCc();
        
        // Store CC addresses as comma-separated string, or null if empty
        $ccString = null;
        if (!empty($originalCc) && is_array($originalCc) && count($originalCc) > 0) {
            $ccString = implode(', ', $originalCc);
            $this->eventLogger->log('debug', "Storing CC addresses for bounce: {$ccString}", null, $this->mailbox['id']);
        } else {
            $this->eventLogger->log('debug', "No CC addresses to store for bounce (originalCc is empty or not array)", null, $this->mailbox['id']);
        }
        
        $params = [
            $this->mailbox['id'],
            $parser->getOriginalTo(),
            $ccString,
            $parser->getOriginalSubject(),
            $parser->getOriginalSentDate(),
            date('Y-m-d H:i:s'),
            $parser->getSmtpCode(),
            $parser->getSmtpReason(),
            $recipientDomain,
            $parser->getDeliverabilityStatus(),
            $parser->getSpamScore(),
            $parser->getDeliverabilityStatus(),
            null, // Will be calculated separately
            $data['headers'] ?? '',
            $parser->getParsedData()['body'] ?? ''
        ];
        
        // Log SQL for debugging (not events_log, so safe)
        $this->db->logSql($stmt->queryString, $params);
        
        $stmt->execute($params);

        return $this->db->lastInsertId();
    }

    private function queueNotifications($bounceId, $ccAddresses) {
        // Test mode does NOT affect queueing - it only affects the recipient when sending
        // Queue notifications for ALL CC addresses found, regardless of test mode
        
        $ccCount = is_array($ccAddresses) ? count($ccAddresses) : 0;
        $this->eventLogger->log('info', "queueNotifications called for bounce ID {$bounceId} with {$ccCount} CC addresses", null, $this->mailbox['id']);
        
        if (empty($ccAddresses) || !is_array($ccAddresses) || $ccCount === 0) {
            $this->eventLogger->log('info', "No CC addresses to queue notifications for bounce ID {$bounceId}", null, $this->mailbox['id']);
            return;
        }

        // Log the CC addresses we're about to queue
        $ccList = implode(', ', array_slice($ccAddresses, 0, 10));
        if (count($ccAddresses) > 10) {
            $ccList .= ' ... (' . (count($ccAddresses) - 10) . ' more)';
        }
        $this->eventLogger->log('info', "Queueing notifications for CC addresses: {$ccList}", null, $this->mailbox['id']);

        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO notifications_queue (bounce_id, recipient_email, status)
            VALUES (?, ?, 'pending')
        ");

        $queuedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        
        foreach ($ccAddresses as $email) {
            $email = trim($email);
            if (empty($email)) {
                $skippedCount++;
                continue;
            }
            
            // Normalize email to lowercase for consistency
            $email = strtolower($email);
            
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $params = [$bounceId, $email];
                    // Log SQL for debugging (not events_log, so safe)
                    $this->db->logSql($stmt->queryString, $params);
                    $stmt->execute($params);
                    
                    // Check if row was actually inserted (INSERT OR IGNORE returns 0 if duplicate)
                    $rowsAffected = $stmt->rowCount();
                    if ($rowsAffected > 0) {
                        $queuedCount++;
                        $this->eventLogger->log('info', "✓ Queued notification for {$email} (bounce ID: {$bounceId})", null, $this->mailbox['id']);
                    } else {
                        $skippedCount++;
                        $this->eventLogger->log('debug', "Notification for {$email} already exists in queue (bounce ID: {$bounceId})", null, $this->mailbox['id']);
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->eventLogger->log('error', "Failed to queue notification for {$email} (bounce ID: {$bounceId}): {$e->getMessage()}", null, $this->mailbox['id']);
                    error_log("MailboxMonitor: Failed to queue notification - " . $e->getMessage() . " | Email: {$email} | Bounce ID: {$bounceId}");
                }
            } else {
                $skippedCount++;
                $this->eventLogger->log('warning', "Skipping invalid email address: {$email} (bounce ID: {$bounceId})", null, $this->mailbox['id']);
            }
        }
        
        // Log summary
        $summary = "Queued {$queuedCount} notification(s) for bounce ID {$bounceId}";
        if ($skippedCount > 0) {
            $summary .= " ({$skippedCount} skipped - duplicates or invalid)";
        }
        if ($errorCount > 0) {
            $summary .= " ({$errorCount} errors)";
        }
        $this->eventLogger->log('info', $summary, null, $this->mailbox['id']);
        
        // Also log to error_log for visibility
        if ($queuedCount > 0) {
            error_log("MailboxMonitor: Successfully queued {$queuedCount} notification(s) for bounce ID {$bounceId}");
        } else {
            error_log("MailboxMonitor: WARNING - No notifications queued for bounce ID {$bounceId} (skipped: {$skippedCount}, errors: {$errorCount})");
        }
    }

    /**
     * Recursively fetch all MIME parts from a message structure
     * This ensures we get ALL parts including deeply nested ones
     */
    private function fetchAllMimeParts($connection, $uid, $structure, &$parts, $prefix = '', $imapFlag = FT_UID) {
        if (!$structure) {
            return;
        }
        
        // If this structure has parts (multipart message)
        if (isset($structure->parts) && is_array($structure->parts)) {
            $partNum = 1;
            foreach ($structure->parts as $part) {
                $partIndex = $prefix ? ($prefix . '.' . $partNum) : (string)$partNum;
                
                // Fetch this part
                $partBody = @\imap_fetchbody($connection, $uid, $partIndex, $imapFlag);
                if ($partBody !== false && $partBody !== '') {
                    $parts[$partIndex] = $partBody;
                }
                
                // If this part is itself multipart or message/rfc822, recurse
                if (isset($part->parts) && is_array($part->parts) && count($part->parts) > 0) {
                    // This is a multipart part - recurse into it
                    $this->fetchAllMimeParts($connection, $uid, $part, $parts, $partIndex, $imapFlag);
                } elseif (isset($part->subtype) && strtolower($part->subtype) === 'rfc822') {
                    // This is a message/rfc822 part - fetch its sub-parts too
                    if (isset($part->parts) && is_array($part->parts)) {
                        $this->fetchAllMimeParts($connection, $uid, $part, $parts, $partIndex, $imapFlag);
                    }
                }
                
                $partNum++;
            }
        } else {
            // Single part message - fetch it if we have a prefix
            if ($prefix) {
                $partBody = @\imap_fetchbody($connection, $uid, $prefix, $imapFlag);
                if ($partBody !== false && $partBody !== '') {
                    $parts[$prefix] = $partBody;
                }
            }
        }
    }

    /**
     * Decode a part for searching - handles base64, quoted-printable, etc.
     * This is a helper to decode parts before passing to EmailParser
     */
    private function decodePartForSearch($content) {
        if (empty($content)) {
            return '';
        }
        
        // Try base64 decode if it looks like base64
        if (preg_match('/^[A-Za-z0-9+\/=\s\r\n]+$/', $content) && strlen($content) > 50) {
            $decoded = @base64_decode($content, true);
            if ($decoded !== false && strlen($decoded) > 10) {
                // Check if decoded looks like text (not binary)
                if (preg_match('/[a-zA-Z0-9\s@\.\-:]+/', $decoded)) {
                    return $decoded;
                }
            }
        }
        
        // Try quoted-printable decode
        if (preg_match('/=[0-9A-F]{2}/i', $content)) {
            $decoded = @quoted_printable_decode($content);
            if ($decoded !== $content && !empty($decoded)) {
                return $decoded;
            }
        }
        
        // Return as-is if no decoding needed or decoding failed
        return $content;
    }

    private function moveMessage($messageNum, $folder, $useUid = false) {
        // Build connection string for folder
        $server = $this->mailbox['imap_server'];
        $port = $this->mailbox['imap_port'];
        $protocol = strtolower($this->mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";
        
        // Get the actual mailbox path for the destination folder using the same robust logic as inbox selection
        $allMailboxes = @\imap_list($this->imapConnection, $connectionString, "*");
        $destMailboxPath = null;
        
        // Try multiple folder name variations to handle case sensitivity and encoding
        $folderVariations = [
            $folder,
            strtoupper($folder),
            strtolower($folder),
            'INBOX' . ($folder !== 'INBOX' ? '/' . $folder : ''),
        ];
        
        if ($allMailboxes) {
            foreach ($allMailboxes as $mb) {
                $folderName = str_replace($baseConnectionString, "", $mb);
                $folderDecoded = \imap_utf7_decode($folderName);
                
                // Check against all variations
                foreach ($folderVariations as $var) {
                    if (strcasecmp($folderDecoded, $var) === 0 || $folderDecoded === $var) {
                        $destMailboxPath = $mb; // Use the full mailbox path from imap_list
                        $this->eventLogger->log('debug', "Found destination folder: '{$folderDecoded}' -> '{$mb}'", null, $this->mailbox['id']);
                        break 2;
                    }
                }
            }
        }
        
        if (!$destMailboxPath) {
            // Log available folders for debugging
            $availableFolders = [];
            if ($allMailboxes) {
                foreach ($allMailboxes as $mb) {
                    $folderName = str_replace($baseConnectionString, "", $mb);
                    $folderDecoded = \imap_utf7_decode($folderName);
                    $availableFolders[] = $folderDecoded;
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('warning', "Destination folder '{$folder}' not found. Available folders: {$foldersList}", null, $this->mailbox['id']);
            
            // Last resort: try constructed path using base connection string
            $destMailboxPath = $baseConnectionString . $folder;
            $this->eventLogger->log('debug', "Using constructed path: '{$destMailboxPath}'", null, $this->mailbox['id']);
        }
        
        // Extract just the folder name from the full path (imap_mail_copy may need just the folder name)
        $folderNameOnly = str_replace($baseConnectionString, "", $destMailboxPath);
        
        // Try multiple approaches: full path, folder name only, and encoded folder name
        $pathsToTry = [
            $destMailboxPath,  // Full path from imap_list
            $folderNameOnly,   // Just the folder name
            $folder,           // Original folder name
        ];
        
        $result = false;
        $lastError = null;
        
        // Determine flags for copy/delete operations
        $copyFlags = $useUid ? CP_UID : 0;
        $deleteFlags = $useUid ? FT_UID : 0;
        
        foreach ($pathsToTry as $pathToTry) {
            $this->eventLogger->log('debug', "Attempting to copy message {$messageNum} to path: '{$pathToTry}' (UID mode: " . ($useUid ? 'yes' : 'no') . ")", null, $this->mailbox['id']);
            
            // Copy message to destination folder
            $result = @\imap_mail_copy($this->imapConnection, $messageNum, $pathToTry, $copyFlags);
            
            if ($result) {
                $this->eventLogger->log('debug', "Successfully copied message {$messageNum} using path: '{$pathToTry}'", null, $this->mailbox['id']);
                break;
            } else {
                $lastError = \imap_last_error();
                $this->eventLogger->log('debug', "Failed to copy with path '{$pathToTry}': {$lastError}", null, $this->mailbox['id']);
                // Clear error and try next path
                \imap_errors();
            }
        }
        
        if ($result) {
            // Delete from source
            @\imap_delete($this->imapConnection, $messageNum, $deleteFlags);
            // Expunge
            @\imap_expunge($this->imapConnection);
            $this->eventLogger->log('debug', "Successfully moved message {$messageNum} to folder '{$folder}'", null, $this->mailbox['id']);
        } else {
            $error = $lastError ?: \imap_last_error() ?: 'Unknown error';
            $this->eventLogger->log('warning', "Failed to move message {$messageNum} to folder '{$folder}'. Tried paths: " . implode(', ', $pathsToTry) . ". Error: {$error}", null, $this->mailbox['id']);
        }
    }

    public function disconnect() {
        if ($this->imapConnection) {
            try {
                // Check if connection is still valid before closing
                @\imap_ping($this->imapConnection);
                @\imap_close($this->imapConnection);
            } catch (ValueError $e) {
                // Connection already closed, ignore
            } catch (Exception $e) {
                // Connection error, ignore
            }
            $this->imapConnection = null;
        }
    }

    public function __destruct() {
        $this->disconnect();
    }
}

