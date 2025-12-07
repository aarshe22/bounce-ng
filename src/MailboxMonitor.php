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
        
        // Select the inbox folder explicitly
        $inboxPath = $connectionString . $inbox;
        
        // Try different folder name variations in case of case sensitivity or namespace issues
        $folderVariations = [
            $inbox,
            strtoupper($inbox),
            strtolower($inbox),
            'INBOX' . ($inbox !== 'INBOX' ? '/' . $inbox : ''),
        ];
        
        $selected = false;
        $selectedPath = '';
        
        foreach ($folderVariations as $folderVar) {
            $testPath = $connectionString . $folderVar;
            $result = @imap_reopen($this->imapConnection, $testPath);
            if ($result) {
                $selected = true;
                $selectedPath = $testPath;
                $this->eventLogger->log('info', "Successfully selected folder: '{$folderVar}'", null, $this->mailbox['id']);
                break;
            }
        }
        
        if (!$selected) {
            $error = imap_last_error();
            // Try to get status to verify folder exists
            $status = @imap_status($this->imapConnection, $inboxPath, SA_MESSAGES);
            if (!$status) {
                // List available mailboxes to help debug
                $mailboxes = @imap_list($this->imapConnection, $connectionString, "*");
                $availableFolders = [];
                if ($mailboxes) {
                    foreach ($mailboxes as $mb) {
                        $folder = str_replace($connectionString, "", $mb);
                        $availableFolders[] = $folder;
                    }
                }
                $foldersList = implode(', ', $availableFolders);
                $this->eventLogger->log('error', "Failed to select inbox folder '{$inbox}': {$error}. Available folders: {$foldersList}", null, $this->mailbox['id']);
                throw new Exception("Failed to select inbox folder '{$inbox}': {$error}. Available folders: {$foldersList}");
            }
            // If status works but reopen doesn't, try to select it using imap_select
            $selectResult = @imap_select($this->imapConnection, $inboxPath);
            if ($selectResult) {
                $selected = true;
                $selectedPath = $inboxPath;
                $this->eventLogger->log('info', "Successfully selected folder using imap_select: '{$inbox}'", null, $this->mailbox['id']);
            } else {
                // If status works but reopen doesn't, use the original path
                $selectedPath = $inboxPath;
                $this->eventLogger->log('warning', "imap_reopen and imap_select failed but folder exists. Using path: {$inboxPath}. Error: {$error}", null, $this->mailbox['id']);
            }
        }
        
        // Verify we're actually in the right folder by checking current mailbox
        $checkboxes = @imap_list($this->imapConnection, $connectionString, "*");
        $currentSelected = '';
        if ($checkboxes) {
            foreach ($checkboxes as $mb) {
                $mbInfo = @imap_status($this->imapConnection, $mb, SA_MESSAGES);
                if ($mbInfo) {
                    // Check if this is the selected mailbox by trying to get message count
                    $testCount = @imap_num_msg($this->imapConnection);
                    if ($testCount >= 0) {
                        $folderName = str_replace($connectionString, "", $mb);
                        $currentSelected = $folderName;
                        break;
                    }
                }
            }
        }
        
        if ($currentSelected && $currentSelected !== $inbox) {
            $this->eventLogger->log('warning', "Currently selected folder '{$currentSelected}' does not match configured folder '{$inbox}'", null, $this->mailbox['id']);
        }

        // Get the currently selected mailbox name
        $currentMailboxInfo = @imap_status($this->imapConnection, $selectedPath ?: $inboxPath, SA_ALL);
        $currentMailboxName = 'unknown';
        if ($currentMailboxInfo) {
            // Extract folder name from the mailbox path
            $currentMailboxName = str_replace($connectionString, "", $selectedPath ?: $inboxPath);
        }
        
        // Verify we're in the right folder - get message count from the selected folder
        // imap_num_msg() returns count for the currently selected mailbox
        // First, make absolutely sure we're in the right folder
        if ($selectedPath) {
            $finalSelect = @imap_reopen($this->imapConnection, $selectedPath);
            if (!$finalSelect) {
                // Last attempt with imap_select
                @imap_select($this->imapConnection, $selectedPath);
            }
        }
        
        // Get message count - use imap_num_msg which counts ALL messages regardless of read status
        $messageCount = @imap_num_msg($this->imapConnection);
        
        // Double-check by getting status directly
        if ($messageCount == 0) {
            $directStatus = @imap_status($this->imapConnection, $selectedPath ?: $inboxPath, SA_MESSAGES);
            if ($directStatus && isset($directStatus->messages)) {
                $messageCount = $directStatus->messages;
                $this->eventLogger->log('info', "Message count from direct imap_status: {$messageCount}", null, $this->mailbox['id']);
            }
        }
        
        // Also verify with imap_status for additional info (use selected path)
        $statusPath = $selectedPath ?: $inboxPath;
        $status = @imap_status($this->imapConnection, $statusPath, SA_MESSAGES | SA_UNSEEN);
        $statusCount = 0;
        $unseenCount = 0;
        if ($status) {
            $statusCount = $status->messages ?? 0;
            $unseenCount = $status->unseen ?? 0;
            if ($statusCount != $messageCount) {
                $this->eventLogger->log('warning', "Message count mismatch: imap_num_msg={$messageCount}, imap_status={$statusCount}", null, $this->mailbox['id']);
            }
            // Use the status count if it's different (more reliable)
            if ($statusCount > $messageCount) {
                $messageCount = $statusCount;
            }
        }
        
        // Log detailed info for debugging
        $this->eventLogger->log('info', "DEBUG: Configured inbox folder: '{$inbox}', Selected path: '{$statusPath}', Current mailbox: '{$currentMailboxName}', imap_num_msg: {$messageCount}, imap_status messages: {$statusCount}, Unseen: {$unseenCount}", null, $this->mailbox['id']);
        
        if ($messageCount == 0) {
            // Check if we can list messages another way
            $searchResult = @imap_search($this->imapConnection, 'ALL');
            if ($searchResult && count($searchResult) > 0) {
                $searchCount = count($searchResult);
                $this->eventLogger->log('warning', "imap_num_msg returned 0 but imap_search('ALL') found {$searchCount} messages. Using search count.", null, $this->mailbox['id']);
                $messageCount = $searchCount;
            } else {
                // List all available folders to help debug
                $allMailboxes = @imap_list($this->imapConnection, $connectionString, "*");
                $availableFolders = [];
                if ($allMailboxes) {
                    foreach ($allMailboxes as $mb) {
                        $folder = str_replace($connectionString, "", $mb);
                        $folder = imap_utf7_decode($folder);
                        $availableFolders[] = $folder;
                    }
                }
                $foldersList = implode(', ', $availableFolders);
                $this->eventLogger->log('warning', "No messages found. Configured folder: '{$inbox}'. Available folders: {$foldersList}", null, $this->mailbox['id']);
            }
        }
        
        $this->eventLogger->log('info', "Processing {$messageCount} messages from inbox '{$inbox}' (all messages, read and unread)", null, $this->mailbox['id']);

        $processedCount = 0;
        $skippedCount = 0;
        $problemCount = 0;

        // Process all messages (1 to messageCount) regardless of read/unread status
        for ($i = 1; $i <= $messageCount; $i++) {
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
        
        // Copy message to destination folder
        $destMailbox = $connectionString . $folder;
        $result = @imap_mail_copy($this->imapConnection, $messageNum, $destMailbox);
        
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

