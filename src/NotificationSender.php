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

            $mail->setFrom($relayProvider['smtp_from_email'], $relayProvider['smtp_from_name']);
            $mail->addAddress($recipient);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);

            $mail->send();

            // Update notification status
            $stmt = $this->db->prepare("
                UPDATE notifications_queue 
                SET status = 'sent', sent_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$notificationId]);

            $this->eventLogger->log('success', "Notification sent to {$recipient}", null, $notification['mailbox_id'], $notification['bounce_id']);

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
}

