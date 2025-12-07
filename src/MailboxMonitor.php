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

        $this->imapConnection = @imap_open(
            $connectionString,
            $this->mailbox['imap_username'],
            $this->mailbox['imap_password']
        );

        if (!$this->imapConnection) {
            $error = imap_last_error();
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
        
        $mailboxes = @imap_list($this->imapConnection, $connectionString, "*");
        
        if ($mailboxes) {
            foreach ($mailboxes as $mailbox) {
                // Extract folder name from full mailbox path
                $folder = str_replace($connectionString, "", $mailbox);
                $folder = imap_utf7_decode($folder);
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
            @imap_close($this->imapConnection);
            $this->imapConnection = null;
        }
        
        // First connect to server root
        $this->imapConnection = @imap_open(
            $connectionString,
            $this->mailbox['imap_username'],
            $this->mailbox['imap_password']
        );
        
        if (!$this->imapConnection) {
            $error = imap_last_error();
            $this->eventLogger->log('error', "Failed to connect to IMAP server: {$error}", null, $this->mailbox['id']);
            throw new Exception("IMAP connection failed: {$error}");
        }
        
        // Get the actual mailbox path from imap_list (more reliable than constructing it)
        $allMailboxes = @imap_list($this->imapConnection, $connectionString, "*");
        $actualMailboxPath = null;
        $folderVariations = [
            $inbox,
            strtoupper($inbox),
            strtolower($inbox),
            'INBOX' . ($inbox !== 'INBOX' ? '/' . $inbox : ''),
        ];
        
        // Find the actual mailbox path that matches our folder name
        if ($allMailboxes) {
            foreach ($allMailboxes as $mb) {
                $folder = str_replace($connectionString, "", $mb);
                $folderDecoded = imap_utf7_decode($folder);
                foreach ($folderVariations as $var) {
                    if (strcasecmp($folderDecoded, $var) === 0 || $folderDecoded === $var) {
                        $actualMailboxPath = $mb; // Use the full mailbox path from imap_list
                        $this->eventLogger->log('info', "Found matching folder: '{$folderDecoded}' -> '{$mb}'", null, $this->mailbox['id']);
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
                    $folder = imap_utf7_decode($folder);
                    $availableFolders[] = $folder;
                }
            }
            $foldersList = implode(', ', $availableFolders);
            $this->eventLogger->log('error', "Folder '{$inbox}' not found. Available folders: {$foldersList}", null, $this->mailbox['id']);
            throw new Exception("Folder '{$inbox}' not found. Available folders: {$foldersList}");
        }
        
        $this->eventLogger->log('info', "About to get status for folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Get message count BEFORE selecting (using imap_status on the mailbox path)
        // This works even if the mailbox isn't selected
        $status = @imap_status($this->imapConnection, $actualMailboxPath, SA_MESSAGES | SA_UNSEEN);
        $messageCount = 0;
        $unseenCount = 0;
        
        if ($status) {
            $messageCount = $status->messages ?? 0;
            $unseenCount = $status->unseen ?? 0;
            $this->eventLogger->log('info', "Message count from imap_status (before selection): {$messageCount} total, {$unseenCount} unseen", null, $this->mailbox['id']);
        } else {
            $error = imap_last_error();
            $this->eventLogger->log('warning', "imap_status failed before selection: {$error}", null, $this->mailbox['id']);
        }
        
        $this->eventLogger->log('info', "About to select folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Select the folder using the actual mailbox path - try imap_select first (more reliable)
        $result = @imap_select($this->imapConnection, $actualMailboxPath);
        $this->eventLogger->log('info', "imap_select result: " . ($result ? 'true' : 'false'), null, $this->mailbox['id']);
        
        if (!$result) {
            $error = imap_last_error();
            $this->eventLogger->log('warning', "imap_select failed: {$error}, trying imap_reopen", null, $this->mailbox['id']);
            // Fallback to imap_reopen
            $result = @imap_reopen($this->imapConnection, $actualMailboxPath);
            $this->eventLogger->log('info', "imap_reopen result: " . ($result ? 'true' : 'false'), null, $this->mailbox['id']);
        }
        
        if (!$result) {
            $error = imap_last_error();
            $this->eventLogger->log('error', "Failed to select folder '{$actualMailboxPath}': {$error}", null, $this->mailbox['id']);
            throw new Exception("Failed to select folder: {$error}");
        }
        
        $this->eventLogger->log('info', "Successfully selected folder: '{$actualMailboxPath}'", null, $this->mailbox['id']);
        
        // Now get message count from the selected mailbox - try multiple methods
        $numMsgCount = @imap_num_msg($this->imapConnection);
        $this->eventLogger->log('info', "Message count from imap_num_msg (after selection): {$numMsgCount}", null, $this->mailbox['id']);
        
        // Use the higher count (status might be more accurate)
        if ($numMsgCount > $messageCount) {
            $messageCount = $numMsgCount;
        }
        
        // Re-check status after selection
        $statusAfter = @imap_status($this->imapConnection, $actualMailboxPath, SA_MESSAGES | SA_UNSEEN);
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
            imap_errors();
            
            // Try different search criteria - imap_search is often more reliable
            $searchOptions = ['ALL', '1:*'];
            foreach ($searchOptions as $criteria) {
                $searchResult = @imap_search($this->imapConnection, $criteria);
                if ($searchResult && is_array($searchResult) && count($searchResult) > 0) {
                    $messageCount = count($searchResult);
                    $this->eventLogger->log('info', "Message count from imap_search('{$criteria}'): {$messageCount}", null, $this->mailbox['id']);
                    break;
                }
            }
            
            // Also try getting UIDs which might work even if message count doesn't
            if ($messageCount == 0) {
                $uids = @imap_search($this->imapConnection, 'ALL', SE_UID);
                if ($uids && is_array($uids) && count($uids) > 0) {
                    $uidCount = count($uids);
                    $messageCount = $uidCount;
                    $this->eventLogger->log('info', "Message count from imap_search UIDs: {$messageCount}", null, $this->mailbox['id']);
                }
            }
            
            // Last resort: try to fetch message 1 to see if it exists
            if ($messageCount == 0) {
                $testHeader = @imap_fetchheader($this->imapConnection, 1);
                if ($testHeader) {
                    // If we can fetch header, there's at least one message
                    // Try to count by attempting to fetch headers sequentially
                    $testCount = 0;
                    for ($i = 1; $i <= 1000; $i++) {
                        $testHdr = @imap_fetchheader($this->imapConnection, $i);
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
                    $folder = imap_utf7_decode($folder);
                    // Get message count for this folder using status
                    $folderStatus = @imap_status($this->imapConnection, $mb, SA_MESSAGES);
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
        $verifyCount = @imap_num_msg($this->imapConnection);
        if ($verifyCount != $messageCount && $verifyCount > 0) {
            $this->eventLogger->log('info', "Message count changed: was {$messageCount}, now {$verifyCount}. Using current count.", null, $this->mailbox['id']);
            $messageCount = $verifyCount;
        } elseif ($verifyCount == 0 && $messageCount > 0) {
            // Status said there are messages but num_msg says 0 - use status count
            $this->eventLogger->log('warning', "imap_num_msg returned 0 but status reported {$messageCount} messages. Using status count.", null, $this->mailbox['id']);
        }

        $this->eventLogger->log('info', "Entering processing loop for {$messageCount} messages", null, $this->mailbox['id']);

        // Process all messages (1 to messageCount) regardless of read/unread status
        for ($i = 1; $i <= $messageCount; $i++) {
            $this->eventLogger->log('debug', "Processing message {$i} of {$messageCount}", null, $this->mailbox['id']);
            try {
                // Fetch message using message number (not UID) - processes all messages
                $header = @imap_headerinfo($this->imapConnection, $i);
                if (!$header) {
                    $this->eventLogger->log('warning', "Could not fetch header for message {$i}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                $body = @imap_body($this->imapConnection, $i);
                $rawHeader = @imap_fetchheader($this->imapConnection, $i);
                if (!$rawHeader) {
                    $this->eventLogger->log('warning', "Could not fetch raw header for message {$i}, skipping", null, $this->mailbox['id']);
                    continue;
                }
                
                $rawEmail = $rawHeader . "\r\n\r\n" . ($body ?: '');

                $parser = new EmailParser($rawEmail);

                if (!$parser->isBounce()) {
                    // Not a bounce, move to skipped
                    $this->moveMessage($i, $skipped);
                    $skippedCount++;
                    $this->eventLogger->log('info', "Message {$i} is not a bounce, moved to skipped", null, $this->mailbox['id']);
                    continue;
                }

                // Extract bounce data
                $originalTo = $parser->getOriginalTo();
                $originalCc = $parser->getOriginalCc();
                $recipientDomain = $parser->getRecipientDomain();

                if (!$originalTo || !$recipientDomain) {
                    // Cannot parse, move to problem
                    $this->moveMessage($i, $problem);
                    $problemCount++;
                    $this->eventLogger->log('warning', "Cannot parse message {$i}: missing original_to or domain", null, $this->mailbox['id']);
                    continue;
                }

                // Store bounce record
                $bounceId = $this->storeBounce($parser, $recipientDomain);

                // Calculate and update trust score
                $trustCalculator = new TrustScoreCalculator();
                $bounceData = $parser->getParsedData();
                $trustScore = $trustCalculator->updateDomainTrustScore($recipientDomain, $bounceData);

                // Queue notifications
                $this->queueNotifications($bounceId, $originalCc);

                // Move to processed
                $this->moveMessage($i, $processed);
                $processedCount++;

                $this->eventLogger->log('success', "Processed bounce for {$originalTo} (Domain: {$recipientDomain}, Trust: {$trustScore})", null, $this->mailbox['id'], $bounceId);

            } catch (Exception $e) {
                // Error processing, move to problem
                $this->moveMessage($i, $problem);
                $problemCount++;
                $this->eventLogger->log('error', "Error processing message {$i}: {$e->getMessage()}", null, $this->mailbox['id']);
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
        
        $stmt->execute([
            $this->mailbox['id'],
            $parser->getOriginalTo(),
            !empty($originalCc) ? implode(', ', $originalCc) : null,
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
        if (empty($ccAddresses)) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO notifications_queue (bounce_id, recipient_email, status)
            VALUES (?, ?, 'pending')
        ");

        foreach ($ccAddresses as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stmt->execute([$bounceId, $email]);
            }
        }
    }

    private function moveMessage($messageNum, $folder) {
        // Build connection string for folder
        $server = $this->mailbox['imap_server'];
        $port = $this->mailbox['imap_port'];
        $protocol = strtolower($this->mailbox['imap_protocol']);
        
        $connectionString = "{{$server}:{$port}";
        if ($protocol === 'ssl' || $protocol === 'tls') {
            $connectionString .= "/{$protocol}";
        }
        $connectionString .= "}";
        
        // Get the actual mailbox path for the destination folder
        $allMailboxes = @imap_list($this->imapConnection, $connectionString, "*");
        $destMailboxPath = null;
        
        if ($allMailboxes) {
            foreach ($allMailboxes as $mb) {
                $folderName = str_replace($connectionString, "", $mb);
                $folderDecoded = imap_utf7_decode($folderName);
                if (strcasecmp($folderDecoded, $folder) === 0 || $folderDecoded === $folder) {
                    $destMailboxPath = $mb;
                    break;
                }
            }
        }
        
        if (!$destMailboxPath) {
            // Fallback to constructed path
            $destMailboxPath = $connectionString . $folder;
        }
        
        // Copy message to destination folder
        $result = @imap_mail_copy($this->imapConnection, $messageNum, $destMailboxPath);
        
        if ($result) {
            // Delete from source
            @imap_delete($this->imapConnection, $messageNum);
            // Expunge
            @imap_expunge($this->imapConnection);
        } else {
            $error = imap_last_error();
            $this->eventLogger->log('warning', "Failed to move message to folder '{$folder}': {$error}", null, $this->mailbox['id']);
        }
    }

    public function disconnect() {
        if ($this->imapConnection) {
            imap_close($this->imapConnection);
        }
    }

    public function __destruct() {
        $this->disconnect();
    }
}
