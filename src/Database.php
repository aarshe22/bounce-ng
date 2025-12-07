<?php

namespace BounceNG;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $this->initializeDatabase();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeDatabase() {
        // Ensure data directory exists
        $dataDir = dirname(DB_PATH);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . DB_PATH);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Create tables if they don't exist
        $this->createTables();

        // Seed SMTP codes if needed
        $this->seedSmtpCodes();
    }

    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT,
            provider TEXT NOT NULL,
            provider_id TEXT NOT NULL,
            is_admin INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME,
            UNIQUE(provider, provider_id)
        );

        CREATE TABLE IF NOT EXISTS relay_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            smtp_host TEXT NOT NULL,
            smtp_port INTEGER NOT NULL,
            smtp_username TEXT NOT NULL,
            smtp_password TEXT NOT NULL,
            smtp_from_email TEXT NOT NULL,
            smtp_from_name TEXT NOT NULL,
            smtp_encryption TEXT DEFAULT 'tls',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mailboxes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            imap_server TEXT NOT NULL,
            imap_port INTEGER NOT NULL,
            imap_protocol TEXT NOT NULL,
            imap_username TEXT NOT NULL,
            imap_password TEXT NOT NULL,
            folder_inbox TEXT DEFAULT 'INBOX',
            folder_processed TEXT DEFAULT 'Processed',
            folder_problem TEXT DEFAULT 'Problem',
            folder_skipped TEXT DEFAULT 'Skipped',
            relay_provider_id INTEGER,
            is_enabled INTEGER DEFAULT 1,
            last_processed DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (relay_provider_id) REFERENCES relay_providers(id)
        );

        CREATE TABLE IF NOT EXISTS bounces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER NOT NULL,
            original_to TEXT NOT NULL,
            original_cc TEXT,
            original_subject TEXT,
            original_sent_date DATETIME,
            bounce_date DATETIME NOT NULL,
            smtp_code TEXT,
            smtp_reason TEXT,
            recipient_domain TEXT NOT NULL,
            bounce_type TEXT,
            spam_score REAL,
            deliverability_status TEXT,
            trust_score INTEGER,
            raw_headers TEXT,
            raw_body TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id)
        );

        CREATE TABLE IF NOT EXISTS recipient_domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL UNIQUE,
            bounce_count INTEGER DEFAULT 0,
            trust_score INTEGER DEFAULT 50,
            last_bounce_date DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS notifications_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bounce_id INTEGER NOT NULL,
            recipient_email TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            sent_at DATETIME,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bounce_id) REFERENCES bounces(id)
        );

        CREATE TABLE IF NOT EXISTS events_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            severity TEXT NOT NULL,
            message TEXT NOT NULL,
            user_id INTEGER,
            mailbox_id INTEGER,
            bounce_id INTEGER,
            metadata TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id),
            FOREIGN KEY (bounce_id) REFERENCES bounces(id)
        );

        CREATE TABLE IF NOT EXISTS smtp_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            description TEXT NOT NULL,
            recommendation TEXT,
            category TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS notification_template (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_bounces_mailbox ON bounces(mailbox_id);
        CREATE INDEX IF NOT EXISTS idx_bounces_domain ON bounces(recipient_domain);
        CREATE INDEX IF NOT EXISTS idx_bounces_date ON bounces(bounce_date);
        CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications_queue(status);
        CREATE INDEX IF NOT EXISTS idx_events_type ON events_log(event_type);
        CREATE INDEX IF NOT EXISTS idx_events_created ON events_log(created_at);
        ";

        $this->pdo->exec($sql);

        // Insert default notification template
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM notification_template");
        $result = $stmt->fetch();
        if ($result['count'] == 0) {
            $this->pdo->exec("
                INSERT INTO notification_template (subject, body) VALUES (
                    'Email Bounce Notification',
                    'Dear Recipient,

This is an automated notification that an email sent to {{original_to}} has bounced.

Bounce Details:
- Original Recipient: {{original_to}}
- Bounce Date: {{bounce_date}}
- SMTP Code: {{smtp_code}}
- Reason: {{smtp_reason}}
- Domain: {{recipient_domain}}

{{recommendation}}

Best regards,
Bounce Monitor System'
                )
            ");
        }
    }

    private function seedSmtpCodes() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM smtp_codes");
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            return; // Already seeded
        }

        $codes = [
            ['421', 'Service not available, closing transmission channel', 'The recipient server is temporarily unavailable. Please retry later.', 'temporary'],
            ['450', 'Requested mail action not taken: mailbox unavailable', 'The recipient mailbox is temporarily unavailable. The email may be queued for delivery.', 'temporary'],
            ['451', 'Requested action aborted: local error in processing', 'A temporary local error occurred. Please retry sending the email.', 'temporary'],
            ['452', 'Requested action not taken: insufficient system storage', 'The recipient server has insufficient storage. Please retry later.', 'temporary'],
            ['500', 'Syntax error, command unrecognized', 'The recipient server encountered a syntax error. This may indicate a server configuration issue.', 'permanent'],
            ['501', 'Syntax error in parameters or arguments', 'Invalid email address format or parameters. Verify the email address is correct.', 'permanent'],
            ['502', 'Command not implemented', 'The recipient server does not support the requested command.', 'permanent'],
            ['503', 'Bad sequence of commands', 'The recipient server encountered a command sequence error.', 'temporary'],
            ['504', 'Command parameter not implemented', 'The recipient server does not support the requested parameter.', 'permanent'],
            ['510', 'Bad email address', 'The email address format is invalid. Verify the email address is correct.', 'permanent'],
            ['511', 'Bad email address', 'The email address format is invalid. Verify the email address is correct.', 'permanent'],
            ['512', 'Host server for the recipient\'s domain name cannot be found', 'The recipient domain does not exist. Verify the domain name is correct.', 'permanent'],
            ['513', 'Address type is incorrect', 'The email address type is incorrect. Verify the email address format.', 'permanent'],
            ['521', 'Host server for the recipient\'s domain name cannot be found', 'The recipient domain does not exist. Verify the domain name is correct.', 'permanent'],
            ['530', 'Access denied', 'Access to the recipient server was denied. This may be due to authentication or policy restrictions.', 'permanent'],
            ['541', 'The recipient address rejected your message', 'The recipient server rejected the message. This may be due to spam filtering or policy restrictions.', 'permanent'],
            ['550', 'Requested action not taken: mailbox unavailable', 'The recipient mailbox does not exist or is unavailable. Verify the email address is correct.', 'permanent'],
            ['551', 'User not local; please try forwarding', 'The recipient is not local to this server. The email may need to be forwarded.', 'permanent'],
            ['552', 'Requested mail action aborted: exceeded storage allocation', 'The recipient mailbox has exceeded its storage quota. Contact the recipient to clear space.', 'permanent'],
            ['553', 'Requested action not taken: mailbox name not allowed', 'The mailbox name is not allowed. Verify the email address format is correct.', 'permanent'],
            ['554', 'Transaction failed', 'The email transaction failed. This may be due to spam filtering or policy restrictions.', 'permanent'],
            ['555', 'MAIL FROM/RCPT TO parameters not recognized or not implemented', 'The recipient server does not recognize the email parameters. Verify the email configuration.', 'permanent'],
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO smtp_codes (code, description, recommendation, category) 
            VALUES (?, ?, ?, ?)
        ");

        foreach ($codes as $code) {
            $stmt->execute($code);
        }

        // Add more common codes
        $additionalCodes = [
            ['250', 'Requested mail action okay, completed', 'The email was successfully delivered.', 'success'],
            ['251', 'User not local; will forward', 'The email will be forwarded to the recipient.', 'success'],
            ['252', 'Cannot VRFY user, but will accept message and attempt delivery', 'The email will be accepted and delivery will be attempted.', 'success'],
        ];

        foreach ($additionalCodes as $code) {
            $stmt->execute($code);
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function query($sql) {
        return $this->pdo->query($sql);
    }

    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}

