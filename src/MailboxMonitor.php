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
                $folder = str_replace($connectionString, "", $mb);
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
                    $folder = str_replace($connectionString, "", $mb);
                    $folder = \imap_utf7_decode($folder);
                    $availableFolders[] = $folder;
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('error', "Inbox folder '{$inbox}' not found. Available folders: {$foldersList}", null, $this->mailbox['id']);
            throw new Exception("Inbox folder '{$inbox}' not found. Available folders: {$foldersList}");
        }
        
        $this->eventLogger->log('info', "About to select folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Verify connection is still valid before selecting
        if (!$this->imapConnection) {
            throw new Exception("IMAP connection is null before folder selection");
        }
        
        // Check if connection is still alive
        $pingResult = @\imap_ping($this->imapConnection);
        if (!$pingResult) {
            $error = \imap_last_error();
            $this->eventLogger->log('error', "IMAP connection is not alive before selection: {$error}", null, $this->mailbox['id']);
            throw new Exception("IMAP connection is not alive: {$error}");
        }
        
        // Select the folder using the actual mailbox path - try imap_reopen first (more reliable for existing connections)
        $result = false;
        $error = null;
        
        // Use imap_reopen directly (works better than imap_select for existing connections)
        try {
            $result = @\imap_reopen($this->imapConnection, $actualMailboxPath);
            $error = \imap_last_error();
            $this->eventLogger->log('info', "imap_reopen result: " . ($result ? 'true' : 'false') . ($error ? " (error: {$error})" : ''), null, $this->mailbox['id']);
        } catch (Exception $e) {
            $error = $e->getMessage();
            $this->eventLogger->log('error', "imap_reopen threw exception: {$error}", null, $this->mailbox['id']);
        } catch (\Error $e) {
            $error = $e->getMessage();
            $this->eventLogger->log('error', "imap_reopen threw error: {$error}", null, $this->mailbox['id']);
        }
        
        // If reopen failed, try closing and reopening the connection
        if (!$result) {
            $this->eventLogger->log('warning', "imap_reopen failed: " . ($error ?: 'unknown error') . ", trying to reconnect", null, $this->mailbox['id']);
            @\imap_close($this->imapConnection);
            $this->imapConnection = @\imap_open($connectionString, $this->mailbox['imap_username'], $this->mailbox['imap_password']);
            if ($this->imapConnection) {
                $result = @\imap_reopen($this->imapConnection, $actualMailboxPath);
                $error = \imap_last_error();
                $this->eventLogger->log('info', "After reconnect, imap_reopen result: " . ($result ? 'true' : 'false') . ($error ? " (error: {$error})" : ''), null, $this->mailbox['id']);
            }
        }
        
        if (!$result) {
            $finalError = $error ?: \imap_last_error() ?: 'Unknown error';
            $this->eventLogger->log('error', "Failed to select folder '{$actualMailboxPath}': {$finalError}", null, $this->mailbox['id']);
            throw new Exception("Failed to select folder: {$finalError}");
        }
        
        $this->eventLogger->log('info', "Successfully selected folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // NO MESSAGE COUNTING - Just blindly fetch all messages and process them
        // Don't trust status/num_msg - just try to fetch messages directly
        // Process ALL messages regardless of read/unread status
        $this->eventLogger->log('info', "Fetching all messages from folder (ignoring read/unread status)...", null, $this->mailbox['id']);
        
        $uids = false;
        $usingUids = true;
        
        // Clear any previous IMAP errors
        @\imap_errors();
        
        // Try with SE_UID first (returns UIDs which are more stable)
        $uids = @\imap_search($this->imapConnection, 'ALL', SE_UID);
        
        if ($uids && is_array($uids) && !empty($uids)) {
            $usingUids = true;
            $this->eventLogger->log('info', "✓ Found " . count($uids) . " messages using imap_search with SE_UID", null, $this->mailbox['id']);
        } else {
            // Fallback: try without SE_UID flag (returns message numbers, not UIDs)
            $uids = @\imap_search($this->imapConnection, 'ALL');
            
            if ($uids && is_array($uids) && !empty($uids)) {
                $usingUids = false; // These are message numbers, not UIDs
                $this->eventLogger->log('info', "✓ Found " . count($uids) . " messages using imap_search (message numbers, not UIDs)", null, $this->mailbox['id']);
            } else {
                // Last resort: sequential fetch - start from 1 and keep going until we can't fetch anymore
                $this->eventLogger->log('info', "imap_search failed, trying sequential fetch starting from message 1...", null, $this->mailbox['id']);
                $uids = [];
                $usingUids = false;
                
                // Keep fetching until we hit an error or empty result
                // Start from 1 and go up to a reasonable limit (10,000 messages)
                for ($msgNum = 1; $msgNum <= 10000; $msgNum++) {
                    $testHeader = @\imap_fetchheader($this->imapConnection, $msgNum);
                    if ($testHeader && !empty(trim($testHeader))) {
                        $uids[] = $msgNum;
                        // Log progress every 100 messages
                        if ($msgNum % 100 == 0) {
                            $this->eventLogger->log('info', "Sequential fetch: found {$msgNum} messages so far...", null, $this->mailbox['id']);
                        }
                    } else {
                        // If we've found some messages and this one fails, we're done
                        if (count($uids) > 0) {
                            break;
                        }
                        // If the first message fails, the folder might be empty (but keep trying a few more)
                        if ($msgNum > 10) {
                            break;
                        }
                    }
                }
                
                if (!empty($uids)) {
                    $this->eventLogger->log('info', "✓ Found " . count($uids) . " messages using sequential fetch", null, $this->mailbox['id']);
                } else {
                    $this->eventLogger->log('warning', "All message fetch methods failed. No messages found in folder.", null, $this->mailbox['id']);
                    return [
                        'processed' => 0,
                        'skipped' => 0,
                        'problems' => 0
                    ];
                }
            }
        }
        
        if (empty($uids) || !is_array($uids)) {
            $this->eventLogger->log('warning', "No messages found in folder '{$inbox}'. Returning early.", null, $this->mailbox['id']);
            return [
                'processed' => 0,
                'skipped' => 0,
                'problems' => 0
            ];
        }
        
        $messageCount = count($uids);
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
                
                // RECURSIVELY fetch ALL MIME parts from the structure
                $rawParts = ['0' => $rawHeader];
                $this->fetchAllMimeParts($this->imapConnection, $uid, $structure, $rawParts, '', $imapFlag);
                
                // Also get full body as fallback
                $fullBody = @\imap_body($this->imapConnection, $uid, $imapFlag) ?: '';
                if (!empty($fullBody)) {
                    $rawParts['FULL_BODY'] = $fullBody;
                }
                
                // Combine all parts - prioritize message/rfc822 parts which contain original email
                $combinedBody = '';
                $messageRfc822Parts = [];
                $otherParts = [];
                
                foreach ($rawParts as $partNum => $partContent) {
                    if ($partNum === '0' || $partNum === 'FULL_BODY') {
                        continue; // Skip header and full body in this loop
                    }
                    // Check if this part is message/rfc822 (embedded email)
                    if (strpos($partContent, 'Content-Type: message/rfc822') !== false || 
                        strpos($partContent, 'Content-Type: message/rfc822-headers') !== false ||
                        preg_match('/^From:/m', $partContent)) {
                        $messageRfc822Parts[] = $partContent;
                    } else {
                        $otherParts[] = $partContent;
                    }
                }
                
                // Prioritize message/rfc822 parts (embedded emails) as they contain original headers
                if (!empty($messageRfc822Parts)) {
                    $combinedBody = implode("\r\n\r\n", $messageRfc822Parts);
                } else {
                    $combinedBody = implode("\r\n\r\n", array_filter($otherParts));
                }
                
                // Combine header with all body parts
                $rawEmail = $rawHeader . "\r\n\r\n" . $combinedBody;
                
                // Also store all parts separately for the parser to search
                $allPartsText = implode("\r\n\r\n", array_filter($rawParts));
                $rawEmail = $rawEmail . "\r\n\r\n---ALL_PARTS---\r\n\r\n" . $allPartsText;

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
        $ccCount = is_array($ccAddresses) ? count($ccAddresses) : 0;
        $this->eventLogger->log('debug', "queueNotifications called for bounce ID {$bounceId} with {$ccCount} CC addresses", null, $this->mailbox['id']);
        
        if (empty($ccAddresses) || !is_array($ccAddresses) || $ccCount === 0) {
            $this->eventLogger->log('info', "No CC addresses to queue notifications for bounce ID {$bounceId}", null, $this->mailbox['id']);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT OR IGNORE INTO notifications_queue (bounce_id, recipient_email, status)
            VALUES (?, ?, 'pending')
        ");

        $queuedCount = 0;
        $skippedCount = 0;
        foreach ($ccAddresses as $email) {
            $email = trim($email);
            if (empty($email)) {
                continue;
            }
            
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $params = [$bounceId, strtolower($email)];
                    // Log SQL for debugging (not events_log, so safe)
                    $this->db->logSql($stmt->queryString, $params);
                    $stmt->execute($params);
                    if ($stmt->rowCount() > 0) {
                        $queuedCount++;
                        $this->eventLogger->log('debug', "Queued notification for {$email} (bounce ID: {$bounceId})", null, $this->mailbox['id']);
                    } else {
                        $skippedCount++;
                        $this->eventLogger->log('debug', "Notification for {$email} already exists (bounce ID: {$bounceId})", null, $this->mailbox['id']);
                    }
                } catch (Exception $e) {
                    $this->eventLogger->log('warning', "Failed to queue notification for {$email}: {$e->getMessage()}", null, $this->mailbox['id']);
                }
            } else {
                $skippedCount++;
                $this->eventLogger->log('debug', "Skipping invalid email address: {$email}", null, $this->mailbox['id']);
            }
        }
        
        $this->eventLogger->log('info', "Queued {$queuedCount} notification(s) for bounce ID {$bounceId}" . ($skippedCount > 0 ? " ({$skippedCount} skipped)" : ""), null, $this->mailbox['id']);
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
                $folderName = str_replace($connectionString, "", $mb);
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
                    $folderName = str_replace($connectionString, "", $mb);
                    $folderDecoded = \imap_utf7_decode($folderName);
                    $availableFolders[] = $folderDecoded;
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('warning', "Destination folder '{$folder}' not found. Available folders: {$foldersList}", null, $this->mailbox['id']);
            
            // Last resort: try constructed path
            $destMailboxPath = $connectionString . $folder;
            $this->eventLogger->log('debug', "Using constructed path: '{$destMailboxPath}'", null, $this->mailbox['id']);
        }
        
        // Extract just the folder name from the full path (imap_mail_copy may need just the folder name)
        $folderNameOnly = str_replace($connectionString, "", $destMailboxPath);
        
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
