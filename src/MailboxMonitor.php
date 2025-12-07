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
        $inbox = $this->mailbox['folder_inbox'];
        $processed = $this->mailbox['folder_processed'];
        $problem = $this->mailbox['folder_problem'];
        $skipped = $this->mailbox['folder_skipped'];

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
        
        $this->eventLogger->log('info', "About to get status for folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Get message count BEFORE selecting (using imap_status on the mailbox path)
        // This works even if the mailbox isn't selected
        $status = @\imap_status($this->imapConnection, $actualMailboxPath, SA_MESSAGES | SA_UNSEEN);
        $messageCount = 0;
        $unseenCount = 0;
        
        if ($status) {
            $messageCount = $status->messages ?? 0;
            $unseenCount = $status->unseen ?? 0;
            $this->eventLogger->log('info', "Message count from imap_status (before selection): {$messageCount} total, {$unseenCount} unseen", null, $this->mailbox['id']);
        } else {
            $error = \imap_last_error();
            $this->eventLogger->log('warning', "imap_status failed before selection: {$error}", null, $this->mailbox['id']);
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
        
        // Select the folder using the actual mailbox path - try imap_select first (more reliable)
        // Use call_user_func with fully qualified function name to ensure we call the global function
        $result = false;
        $error = null;
        
        try {
            // Call the global imap_select function using call_user_func
            $result = @call_user_func('\\imap_select', $this->imapConnection, $actualMailboxPath);
            $error = \imap_last_error();
            $this->eventLogger->log('info', "imap_select result: " . ($result ? 'true' : 'false') . ($error ? " (error: {$error})" : ''), null, $this->mailbox['id']);
        } catch (Exception $e) {
            $error = $e->getMessage();
            $this->eventLogger->log('error', "imap_select threw exception: {$error}", null, $this->mailbox['id']);
        } catch (\Error $e) {
            $error = $e->getMessage();
            $this->eventLogger->log('error', "imap_select threw error: {$error}", null, $this->mailbox['id']);
        }
        
        if (!$result) {
            if ($error) {
                $this->eventLogger->log('warning', "imap_select failed: {$error}, trying imap_reopen", null, $this->mailbox['id']);
            }
            // Fallback to imap_reopen
            try {
                $result = @call_user_func('\\imap_reopen', $this->imapConnection, $actualMailboxPath);
                $error = \imap_last_error();
                $this->eventLogger->log('info', "imap_reopen result: " . ($result ? 'true' : 'false') . ($error ? " (error: {$error})" : ''), null, $this->mailbox['id']);
            } catch (Exception $e) {
                $error = $e->getMessage();
                $this->eventLogger->log('error', "imap_reopen threw exception: {$error}", null, $this->mailbox['id']);
            } catch (\Error $e) {
                $error = $e->getMessage();
                $this->eventLogger->log('error', "imap_reopen threw error: {$error}", null, $this->mailbox['id']);
            }
        }
        
        if (!$result) {
            $finalError = $error ?: \imap_last_error() ?: 'Unknown error';
            $this->eventLogger->log('error', "Failed to select folder '{$actualMailboxPath}': {$finalError}", null, $this->mailbox['id']);
            throw new Exception("Failed to select folder: {$finalError}");
        }
        
        $this->eventLogger->log('info', "Successfully selected folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Now get message count from the selected mailbox - try multiple methods
        $numMsgCount = @\imap_num_msg($this->imapConnection);
        $this->eventLogger->log('info', "Message count from imap_num_msg (after selection): {$numMsgCount}", null, $this->mailbox['id']);
        
        // Use the higher count (status might be more accurate)
        if ($numMsgCount > $messageCount) {
            $messageCount = $numMsgCount;
        }
        
        // Re-check status after selection
        $statusAfter = @\imap_status($this->imapConnection, $actualMailboxPath, SA_MESSAGES | SA_UNSEEN);
        if ($statusAfter) {
            $statusCount = $statusAfter->messages ?? 0;
            $unseenCount = $statusAfter->unseen ?? 0;
            $this->eventLogger->log('info', "Message count from imap_status (after selection): {$statusCount} total, {$unseenCount} unseen", null, $this->mailbox['id']);
            if ($statusCount > $messageCount) {
                $messageCount = $statusCount;
            }
        }
        
        // If still 0, try imap_search as backup - this is the most reliable method
        if ($messageCount == 0) {
            // Clear any previous errors
            \imap_errors();
            
            // Try different search criteria - imap_search is often more reliable
            $searchOptions = ['ALL', '1:*'];
            foreach ($searchOptions as $criteria) {
                $searchResult = @\imap_search($this->imapConnection, $criteria);
                if ($searchResult && is_array($searchResult) && count($searchResult) > 0) {
                    $messageCount = count($searchResult);
                    $this->eventLogger->log('info', "Message count from imap_search('{$criteria}'): {$messageCount}", null, $this->mailbox['id']);
                    break;
                }
            }
            
            // Also try getting UIDs which might work even if message count doesn't
            if ($messageCount == 0) {
                $uids = @\imap_search($this->imapConnection, 'ALL', SE_UID);
                if ($uids && is_array($uids) && count($uids) > 0) {
                    $uidCount = count($uids);
                    $messageCount = $uidCount;
                    $this->eventLogger->log('info', "Message count from imap_search UIDs: {$messageCount}", null, $this->mailbox['id']);
                }
            }
            
            // Last resort: try to fetch message 1 to see if it exists
            if ($messageCount == 0) {
                $testHeader = @\imap_fetchheader($this->imapConnection, 1);
                if ($testHeader) {
                    // If we can fetch header, there's at least one message
                    // Try to count by attempting to fetch headers sequentially
                    $testCount = 0;
                    for ($i = 1; $i <= 1000; $i++) {
                        $testHdr = @\imap_fetchheader($this->imapConnection, $i);
                        if (!$testHdr) {
                            break;
                        }
                        $testCount = $i;
                    }
                    if ($testCount > 0) {
                        $messageCount = $testCount;
                        $this->eventLogger->log('info', "Message count from sequential header fetch: {$messageCount}", null, $this->mailbox['id']);
                    }
                }
            }
        }
        
        // Log detailed info for debugging
        $this->eventLogger->log('info', "DEBUG: Configured inbox folder: '{$inbox}', Actual mailbox path: '{$actualMailboxPath}', Final message count: {$messageCount}, Unseen: {$unseenCount}", null, $this->mailbox['id']);
        
        if ($messageCount == 0) {
            // List all available folders and their message counts to help debug
            $availableFolders = [];
            if ($allMailboxes) {
                foreach ($allMailboxes as $mb) {
                    $folder = str_replace($connectionString, "", $mb);
                    $folder = \imap_utf7_decode($folder);
                    // Get message count for this folder using status
                    $folderStatus = @\imap_status($this->imapConnection, $mb, SA_MESSAGES);
                    $folderMsgCount = $folderStatus ? ($folderStatus->messages ?? 0) : 0;
                    if ($folderMsgCount > 0 || $folder === $inbox) {
                        $availableFolders[] = "{$folder} ({$folderMsgCount} msgs)";
                    }
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('warning', "No messages found in folder '{$inbox}'. Available folders: {$foldersList}", null, $this->mailbox['id']);
            // Return early if no messages
            return [
                'processed' => 0,
                'skipped' => 0,
                'problems' => 0
            ];
        }
        
        $this->eventLogger->log('info', "Starting to process {$messageCount} messages from inbox '{$inbox}' (all messages, read and unread)", null, $this->mailbox['id']);

        $processedCount = 0;
        $skippedCount = 0;
        $problemCount = 0;

        // Verify we have a valid connection and folder is selected
        if (!$this->imapConnection) {
            $this->eventLogger->log('error', "IMAP connection lost before processing", null, $this->mailbox['id']);
            throw new Exception("IMAP connection lost before processing");
        }

        // Double-check message count right before processing
        $verifyCount = @\imap_num_msg($this->imapConnection);
        if ($verifyCount != $messageCount && $verifyCount > 0) {
            $this->eventLogger->log('info', "Message count changed: was {$messageCount}, now {$verifyCount}. Using current count.", null, $this->mailbox['id']);
            $messageCount = $verifyCount;
        } elseif ($verifyCount == 0 && $messageCount > 0) {
            // Status said there are messages but num_msg says 0 - use status count
            $this->eventLogger->log('warning', "imap_num_msg returned 0 but status reported {$messageCount} messages. Using status count.", null, $this->mailbox['id']);
        }

        $this->eventLogger->log('info', "Entering processing loop for {$messageCount} messages", null, $this->mailbox['id']);

        // Get all message UIDs first to avoid issues with message numbers shifting when messages are moved
        $uids = @\imap_search($this->imapConnection, 'ALL', SE_UID);
        if (!$uids || !is_array($uids)) {
            $this->eventLogger->log('warning', "Could not get message UIDs, falling back to sequential processing", null, $this->mailbox['id']);
            $uids = range(1, $messageCount);
        }
        
        $this->eventLogger->log('info', "Processing " . count($uids) . " messages using UIDs", null, $this->mailbox['id']);
        
        // Process messages in reverse order to avoid number shifting issues
        $uids = array_reverse($uids);
        
        foreach ($uids as $index => $uid) {
            $this->eventLogger->log('debug', "Processing message " . ($index + 1) . " of " . count($uids) . " (UID: {$uid})", null, $this->mailbox['id']);
            try {
                // Fetch message structure to understand MIME parts
                $structure = @\imap_fetchstructure($this->imapConnection, $uid, FT_UID);
                if (!$structure) {
                    $this->eventLogger->log('warning', "Could not fetch structure for message UID {$uid}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                // Fetch all body parts using imap_fetchbody (like the example code)
                $rawParts = [];
                
                // Part 0 = headers
                $rawHeader = @\imap_fetchbody($this->imapConnection, $uid, '0', FT_UID);
                // Check for both false and empty string - imap_fetchbody can return empty string if part doesn't exist
                if ($rawHeader === false || $rawHeader === '') {
                    // Fallback to imap_fetchheader
                    $rawHeader = @\imap_fetchheader($this->imapConnection, $uid, FT_UID);
                }
                
                // Final check - if still empty/false after fallback, skip this message
                if (!$rawHeader || $rawHeader === '') {
                    $this->eventLogger->log('warning', "Could not fetch header for message UID {$uid}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                $rawParts['0'] = $rawHeader;
                
                // Part 1 = first body part (often multipart/alternative)
                $rawParts['1'] = @\imap_fetchbody($this->imapConnection, $uid, '1', FT_UID) ?: '';
                $rawParts['1.1'] = @\imap_fetchbody($this->imapConnection, $uid, '1.1', FT_UID) ?: '';
                $rawParts['1.2'] = @\imap_fetchbody($this->imapConnection, $uid, '1.2', FT_UID) ?: '';
                
                // Part 2 = message/rfc822 (attached original message - this is where CC usually is!)
                $rawParts['2'] = @\imap_fetchbody($this->imapConnection, $uid, '2', FT_UID) ?: '';
                $rawParts['2.0'] = @\imap_fetchbody($this->imapConnection, $uid, '2.0', FT_UID) ?: '';
                $rawParts['2.1'] = @\imap_fetchbody($this->imapConnection, $uid, '2.1', FT_UID) ?: '';
                $rawParts['2.2'] = @\imap_fetchbody($this->imapConnection, $uid, '2.2', FT_UID) ?: '';
                $rawParts['2.3'] = @\imap_fetchbody($this->imapConnection, $uid, '2.3', FT_UID) ?: '';
                
                // Also get full body as fallback
                $fullBody = @\imap_body($this->imapConnection, $uid, FT_UID) ?: '';
                
                // Combine all parts for parsing - prioritize part 2 (message/rfc822) which contains original email
                $combinedBody = '';
                if (!empty($rawParts['2'])) {
                    // Part 2 is the embedded original message - this is where CC usually is!
                    $combinedBody = $rawParts['2'];
                } else {
                    // Fallback to other parts
                    $combinedBody = implode("\r\n\r\n", array_filter([
                        $rawParts['1'] ?? '',
                        $rawParts['1.1'] ?? '',
                        $rawParts['1.2'] ?? '',
                        $fullBody
                    ]));
                }
                
                // Combine header with all body parts
                $rawEmail = $rawHeader . "\r\n\r\n" . $combinedBody;
                
                // Also store all parts separately for the parser to search
                $allPartsText = implode("\r\n\r\n", array_filter($rawParts));
                $rawEmail = $rawEmail . "\r\n\r\n---ALL_PARTS---\r\n\r\n" . $allPartsText;

                $parser = new EmailParser($rawEmail);

                if (!$parser->isBounce()) {
                    // Not a bounce, move to skipped
                    $this->moveMessage($uid, $skipped, true); // true = use UID
                    $skippedCount++;
                    $this->eventLogger->log('info', "Message UID {$uid} is not a bounce, moved to skipped", null, $this->mailbox['id']);
                    continue;
                }

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
                    $this->moveMessage($uid, $problem, true); // true = use UID
                    $problemCount++;
                    $this->eventLogger->log('warning', "Cannot parse message UID {$uid}: missing original_to or domain", null, $this->mailbox['id']);
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
                $this->moveMessage($uid, $processed, true); // true = use UID
                $processedCount++;

                $ccCount = is_array($originalCc) ? count($originalCc) : 0;
                $this->eventLogger->log('success', "Processed bounce for {$originalTo} (Domain: {$recipientDomain}, Trust: {$trustScore}, CC: {$ccCount} addresses)", null, $this->mailbox['id'], $bounceId);

            } catch (Exception $e) {
                // Error processing, move to problem
                $this->moveMessage($uid, $problem, true); // true = use UID
                $problemCount++;
                $this->eventLogger->log('error', "Error processing message UID {$uid}: {$e->getMessage()}", null, $this->mailbox['id']);
            }
        }

        // Update last processed time
        $stmt = $this->db->prepare("UPDATE mailboxes SET last_processed = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$this->mailbox['id']]);

        $this->eventLogger->log('info', "Processing complete: {$processedCount} processed, {$skippedCount} skipped, {$problemCount} problems", null, $this->mailbox['id']);

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
        
        $stmt->execute([
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
        ]);

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
                    $stmt->execute([$bounceId, strtolower($email)]);
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
