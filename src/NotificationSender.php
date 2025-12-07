<?php

namespace BounceNG;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class NotificationSender {
    private $db;
    private $eventLogger;
    private $testMode = false;
    private $overrideEmail = '';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->eventLogger = new EventLogger();
    }

    public function setTestMode($enabled, $overrideEmail = '') {
        $this->testMode = $enabled;
        $this->overrideEmail = $overrideEmail ?: TEST_MODE_OVERRIDE_EMAIL;
    }

    public function sendNotification($notificationId) {
        $stmt = $this->db->prepare("
            SELECT nq.*, b.*, nt.subject as template_subject, nt.body as template_body
            FROM notifications_queue nq
            JOIN bounces b ON nq.bounce_id = b.id
            JOIN notification_template nt
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
        $recipient = $this->testMode ? $this->overrideEmail : $notification['recipient_email'];
        $subject = $this->replaceTemplateVars($notification['template_subject'], $notification);
        $body = $this->replaceTemplateVars($notification['template_body'], $notification, $recommendation);

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
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

