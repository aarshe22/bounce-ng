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
        if (!$this->imapConnection) {
            $this->connect();
        }

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
        
        // Select inbox folder
        $inboxPath = $connectionString . $inbox;
        $result = @imap_reopen($this->imapConnection, $inboxPath);
        
        if (!$result) {
            $error = imap_last_error();
            $this->eventLogger->log('error', "Failed to select inbox folder '{$inbox}': {$error}", null, $this->mailbox['id']);
            throw new Exception("Failed to select inbox folder '{$inbox}': {$error}");
        }

        // Get message count
        $messageCount = imap_num_msg($this->imapConnection);
        $this->eventLogger->log('info', "Processing {$messageCount} messages from inbox '{$inbox}'", null, $this->mailbox['id']);

        $processedCount = 0;
        $skippedCount = 0;
        $problemCount = 0;

        for ($i = 1; $i <= $messageCount; $i++) {
            try {
                $header = imap_headerinfo($this->imapConnection, $i);
                $body = imap_body($this->imapConnection, $i);
                $rawEmail = imap_fetchheader($this->imapConnection, $i) . "\r\n\r\n" . $body;

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

