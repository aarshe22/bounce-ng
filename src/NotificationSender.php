<?php

namespace BounceNG;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationSender {
    private $db;
    private $eventLogger;
    private $testMode = false;
    private $overrideEmail = '';
    private $relayProviderId = null;

    public function __construct($relayProviderId = null) {
        $this->db = Database::getInstance();
        $this->eventLogger = new EventLogger();
        $this->relayProviderId = $relayProviderId;
    }

    /**
     * Set test mode - ONLY affects the recipient email when sending notifications
     * Test mode does NOT affect:
     * - Bounce detection
     * - CC address extraction
     * - Notification queueing
     * - Any other processing
     * 
     * When test mode is enabled, notifications are sent to the override email
     * instead of the original CC addresses, but all other behavior is identical.
     */
    public function setTestMode($enabled, $overrideEmail = '') {
        $this->testMode = $enabled;
        $this->overrideEmail = $overrideEmail ?: TEST_MODE_OVERRIDE_EMAIL;
    }

    public function sendNotification($notificationId) {
        $stmt = $this->db->prepare("
            SELECT nq.*, b.*, m.relay_provider_id, nt.subject as template_subject, nt.body as template_body
            FROM notifications_queue nq
            JOIN bounces b ON nq.bounce_id = b.id
            JOIN mailboxes m ON b.mailbox_id = m.id
            CROSS JOIN (SELECT subject, body FROM notification_template ORDER BY id DESC LIMIT 1) nt
            WHERE nq.id = ?
        ");
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch();

        if (!$notification) {
            throw new \Exception("Notification not found");
        }

        if ($notification['status'] !== 'pending') {
            return false; // Already processed
        }

        // Get relay provider (use from notification/mailbox or fallback to constructor param)
        $relayProviderId = $notification['relay_provider_id'] ?? $this->relayProviderId;
        if (!$relayProviderId) {
            throw new \Exception("No relay provider configured for this mailbox");
        }

        $stmt = $this->db->prepare("SELECT * FROM relay_providers WHERE id = ? AND is_active = 1");
        $stmt->execute([$relayProviderId]);
        $relayProvider = $stmt->fetch();

        if (!$relayProvider) {
            throw new \Exception("Relay provider not found or inactive");
        }

        // Get SMTP code details
        $smtpCode = $notification['smtp_code'];
        $recommendation = '';
        if ($smtpCode) {
            $stmt = $this->db->prepare("SELECT recommendation FROM smtp_codes WHERE code = ?");
            $stmt->execute([$smtpCode]);
            $codeData = $stmt->fetch();
            if ($codeData) {
                $recommendation = $codeData['recommendation'];
            }
        }

        // Prepare email
        // TEST MODE: Only override the recipient - all other processing is identical
        // Notifications are still queued normally, test mode only changes where they're sent
        $recipient = $this->testMode ? $this->overrideEmail : $notification['recipient_email'];
        $subject = $this->replaceTemplateVars($notification['template_subject'], $notification);
        $body = $this->replaceTemplateVars($notification['template_body'], $notification, $recommendation);

        // Get BCC monitoring settings (only in non-test mode)
        $bccEmails = [];
        if (!$this->testMode) {
            $bccEnabledStmt = $this->db->prepare("SELECT value FROM settings WHERE key = 'bcc_monitoring_enabled'");
            $bccEnabledStmt->execute();
            $bccEnabled = $bccEnabledStmt->fetch();
            
            if ($bccEnabled && $bccEnabled['value'] === '1') {
                $bccEmailsStmt = $this->db->prepare("SELECT value FROM settings WHERE key = 'bcc_monitoring_emails'");
                $bccEmailsStmt->execute();
                $bccEmailsData = $bccEmailsStmt->fetch();
                
                if ($bccEmailsData && !empty($bccEmailsData['value'])) {
                    // Parse comma-separated email addresses
                    $emailList = explode(',', $bccEmailsData['value']);
                    foreach ($emailList as $email) {
                        $email = trim($email);
                        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $bccEmails[] = $email;
                        }
                    }
                }
            }
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $relayProvider['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $relayProvider['smtp_username'];
            $mail->Password = $relayProvider['smtp_password'];
            
            // Set encryption based on provider setting
            $encryption = strtolower($relayProvider['smtp_encryption']);
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->Port = (int)$relayProvider['smtp_port'];

            // Set from address with proper encoding
            $fromEmail = $relayProvider['smtp_from_email'];
            $fromName = $relayProvider['smtp_from_name'];
            $mail->setFrom($fromEmail, $fromName);
            
            // Set reply-to to match from address (improves deliverability)
            $mail->addReplyTo($fromEmail, $fromName);
            
            // Add recipient
            $mail->addAddress($recipient);
            
            // Add BCC monitoring emails if enabled (non-test mode only)
            foreach ($bccEmails as $bccEmail) {
                $mail->addBCC($bccEmail);
            }

            // Format subject with proper encoding
            $mail->Subject = $subject;
            
            // Format body for maximum deliverability
            // Use plain text with proper line breaks and formatting
            $formattedBody = $this->formatEmailBody($body);
            $mail->Body = $formattedBody;
            $mail->isHTML(false);
            
            // Set character encoding
            $mail->CharSet = 'UTF-8';
            
            // Add headers for better deliverability
            $mail->addCustomHeader('X-Mailer', 'Bounce Monitor System');
            $mail->addCustomHeader('X-Priority', '3'); // Normal priority
            $mail->addCustomHeader('Precedence', 'bulk'); // Indicate automated message
            
            // Set message ID for tracking
            $messageId = '<' . uniqid('bounce-monitor-', true) . '@' . parse_url($fromEmail, PHP_URL_HOST) . '>';
            $mail->MessageID = $messageId;

            $mail->send();

            // Update notification status
            $stmt = $this->db->prepare("
                UPDATE notifications_queue 
                SET status = 'sent', sent_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$notificationId]);

            $bccInfo = !empty($bccEmails) ? ' (BCC: ' . implode(', ', $bccEmails) . ')' : '';
            $this->eventLogger->log('success', "Notification sent to {$recipient}{$bccInfo}", null, $notification['mailbox_id'], $notification['bounce_id']);

            return true;

        } catch (PHPMailerException $e) {
            // Update notification status with error
            $stmt = $this->db->prepare("
                UPDATE notifications_queue 
                SET status = 'failed', error_message = ? 
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $notificationId]);

            $this->eventLogger->log('error', "Failed to send notification: {$e->getMessage()}", null, $notification['mailbox_id'], $notification['bounce_id']);

            return false;
        }
    }

    public function sendPendingNotifications($realTime = true) {
        if ($realTime) {
            // Send all pending notifications immediately
            $stmt = $this->db->prepare("
                SELECT id FROM notifications_queue 
                WHERE status = 'pending' 
                ORDER BY created_at ASC
            ");
            $stmt->execute();
            $notifications = $stmt->fetchAll();

            foreach ($notifications as $notification) {
                $this->sendNotification($notification['id']);
            }
        }
        // If not real-time, notifications stay in queue for manual sending
    }

    /**
     * Send a test email using the same SMTP profile as notifications.
     * Uses the first active relay provider if $relayProviderId is null.
     *
     * @param string $toEmail Recipient email address
     * @param int|null $relayProviderId Optional relay provider ID; if null, first active relay is used
     * @return bool True on success
     * @throws \Exception If no relay configured, invalid email, or send failure
     */
    public function sendTestEmail($toEmail, $relayProviderId = null) {
        $toEmail = trim($toEmail);
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid email address");
        }

        $relayProviderId = $relayProviderId ?: $this->relayProviderId;
        if (!$relayProviderId) {
            $stmt = $this->db->query("SELECT id FROM relay_providers WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            if (!$row) {
                throw new \Exception("No active relay provider configured. Add an SMTP relay in Control Panel → Relay Providers.");
            }
            $relayProviderId = (int)$row['id'];
        }

        $stmt = $this->db->prepare("SELECT * FROM relay_providers WHERE id = ? AND is_active = 1");
        $stmt->execute([$relayProviderId]);
        $relayProvider = $stmt->fetch();
        if (!$relayProvider) {
            throw new \Exception("Relay provider not found or inactive");
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $relayProvider['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $relayProvider['smtp_username'];
            $mail->Password = $relayProvider['smtp_password'];
            $encryption = strtolower($relayProvider['smtp_encryption']);
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Port = (int)$relayProvider['smtp_port'];

            $fromEmail = $relayProvider['smtp_from_email'];
            $fromName = $relayProvider['smtp_from_name'];
            $mail->setFrom($fromEmail, $fromName);
            $mail->addReplyTo($fromEmail, $fromName);
            $mail->addAddress($toEmail);

            $mail->Subject = 'Bounce Monitor – Test Email';
            $mail->Body = "This is a test email from Bounce Monitor.\r\n\r\nYour SMTP configuration is working correctly.";
            $mail->isHTML(false);
            $mail->CharSet = 'UTF-8';
            $mail->addCustomHeader('X-Mailer', 'Bounce Monitor System');

            $mail->send();
            $this->eventLogger->log('success', "Test email sent to {$toEmail}", null, null, null);
            return true;
        } catch (PHPMailerException $e) {
            $this->eventLogger->log('error', "Failed to send test email: {$e->getMessage()}", null, null, null);
            throw new \Exception($e->getMessage());
        }
    }

    private function replaceTemplateVars($template, $data, $recommendation = '') {
        $replacements = [
            '{{original_to}}' => $data['original_to'] ?? '',
            '{{original_cc}}' => $data['original_cc'] ?? '',
            '{{original_subject}}' => $data['original_subject'] ?? '',
            '{{bounce_date}}' => $data['bounce_date'] ?? '',
            '{{smtp_code}}' => $data['smtp_code'] ?? '',
            '{{smtp_reason}}' => $data['smtp_reason'] ?? '',
            '{{recipient_domain}}' => $data['recipient_domain'] ?? '',
            '{{recommendation}}' => $recommendation,
        ];

        $result = $template;
        foreach ($replacements as $key => $value) {
            $result = str_replace($key, $value, $result);
        }

        return $result;
    }

    /**
     * Format email body for maximum deliverability
     * - Ensures proper line breaks
     * - Removes excessive whitespace
     * - Ensures proper text encoding
     * - Adds proper spacing for readability
     */
    private function formatEmailBody($body) {
        // Normalize line endings to CRLF (RFC 5322 standard)
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        
        // Remove excessive blank lines (more than 2 consecutive)
        $body = preg_replace('/\r\n\s*\r\n\s*\r\n+/', "\r\n\r\n", $body);
        
        // Ensure lines don't exceed 78 characters (RFC 5322 recommendation)
        // But preserve intentional formatting, so only wrap very long lines
        $lines = explode("\r\n", $body);
        $formattedLines = [];
        foreach ($lines as $line) {
            if (strlen($line) > 78 && !preg_match('/^[\s-]+$/', $line)) {
                // Soft wrap long lines at word boundaries
                $wrapped = wordwrap($line, 78, "\r\n ", true);
                $formattedLines[] = $wrapped;
            } else {
                $formattedLines[] = $line;
            }
        }
        $body = implode("\r\n", $formattedLines);
        
        // Trim trailing whitespace from each line
        $body = preg_replace('/[ \t]+$/m', '', $body);
        
        // Ensure body ends with a newline
        if (substr($body, -2) !== "\r\n") {
            $body .= "\r\n";
        }
        
        return $body;
    }
}

