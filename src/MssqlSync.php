<?php

namespace BounceNG;

use PDO;
use PDOException;

/**
 * Syncs confirmed hard-bounce bad addresses to a remote MSSQL table.
 * Uses settings: mssql_server, mssql_port, mssql_database, mssql_table, mssql_username, mssql_password.
 */
class MssqlSync {
    private $db;
    private $eventLogger;
    private $config = null;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->eventLogger = new EventLogger();
    }

    /**
     * Load MSSQL config from settings. Returns associative array; missing keys are empty string.
     */
    public function getConfig() {
        if ($this->config !== null) {
            return $this->config;
        }
        $keys = ['mssql_server', 'mssql_port', 'mssql_database', 'mssql_table', 'mssql_username', 'mssql_password', 'mssql_trust_certificate', 'mssql_last_synced_at', 'mssql_manually_unsynced'];
        $config = [];
        foreach ($keys as $key) {
            $stmt = $this->db->prepare("SELECT value FROM settings WHERE key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            $config[$key] = $row ? $row['value'] : '';
        }
        $this->config = $config;
        return $config;
    }

    /**
     * Get config with password masked for API response.
     */
    public function getConfigMasked() {
        $c = $this->getConfig();
        $c['mssql_password'] = $c['mssql_password'] !== '' ? '********' : '';
        return $c;
    }

    /**
     * Return whether MSSQL config is complete enough to connect.
     */
    public function isConfigured() {
        $c = $this->getConfig();
        return trim($c['mssql_server'] ?? '') !== ''
            && trim($c['mssql_database'] ?? '') !== ''
            && trim($c['mssql_table'] ?? '') !== ''
            && trim($c['mssql_username'] ?? '') !== ''
            && trim($c['mssql_password'] ?? '') !== '';
    }

    /**
     * Build PDO DSN for MSSQL. Prefer sqlsrv (Windows), fallback to dblib (FreeTDS).
     * When $trustCertificate is true, adds TrustServerCertificate=yes for sqlsrv (e.g. self-signed certs).
     */
    private function buildDsn($server, $port, $database, $trustCertificate = false) {
        $port = (int) $port ?: 1433;
        $server = trim($server);
        $database = trim($database);
        $drivers = PDO::getAvailableDrivers();
        if (in_array('sqlsrv', $drivers, true)) {
            $dsn = "sqlsrv:Server=" . $server . "," . $port . ";Database=" . $database;
            if ($trustCertificate) {
                $dsn .= ";TrustServerCertificate=yes";
            }
            return $dsn;
        }
        if (in_array('dblib', $drivers, true)) {
            return "dblib:host=" . $server . ";port=" . $port . ";dbname=" . $database;
        }
        throw new \Exception("No MSSQL PDO driver available. Install pdo_sqlsrv (Windows) or pdo_dblib with FreeTDS (Linux).");
    }

    /**
     * Create PDO connection to MSSQL. Throws on failure.
     *
     * @return PDO
     */
    public function getConnection() {
        $c = $this->getConfig();
        $server = trim($c['mssql_server'] ?? '');
        $port = trim($c['mssql_port'] ?? '') ?: '1433';
        $database = trim($c['mssql_database'] ?? '');
        $table = trim($c['mssql_table'] ?? '');
        $username = trim($c['mssql_username'] ?? '');
        $password = $c['mssql_password'] ?? '';

        if ($server === '' || $database === '' || $table === '' || $username === '') {
            throw new \Exception("MSSQL sync is not fully configured. Set server, database, table, and username in Control Panel.");
        }

        $trustCertificate = isset($c['mssql_trust_certificate']) && $c['mssql_trust_certificate'] === '1';
        $dsn = $this->buildDsn($server, $port, $database, $trustCertificate);
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return $pdo;
    }

    /**
     * Test connection to MSSQL. Returns true on success.
     *
     * @return bool
     */
    public function testConnection() {
        $pdo = $this->getConnection();
        $pdo->query("SELECT 1");
        return true;
    }

    /**
     * Get emails that the user has manually set to "unsync" (excluded from auto-sync until they press SYNC again).
     *
     * @return array<string> Lowercase emails
     */
    public function getManuallyUnsyncedEmails() {
        $raw = trim($this->getConfig()['mssql_manually_unsynced'] ?? '');
        if ($raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        return array_values(array_unique(array_map(function ($e) {
            return strtolower(trim((string) $e));
        }, array_filter($arr, function ($e) {
            return $e !== '' && $e !== null;
        }))));
    }

    public function addManuallyUnsynced($email) {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return;
        }
        $list = $this->getManuallyUnsyncedEmails();
        if (in_array($email, $list, true)) {
            return;
        }
        $list[] = $email;
        $this->setSetting('mssql_manually_unsynced', json_encode(array_values($list)));
        $this->config = null;
    }

    public function removeManuallyUnsynced($email) {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return;
        }
        $list = array_values(array_filter($this->getManuallyUnsyncedEmails(), function ($e) use ($email) {
            return $e !== $email;
        }));
        $this->setSetting('mssql_manually_unsynced', empty($list) ? '' : json_encode($list));
        $this->config = null;
    }

    private function setSetting($key, $value) {
        $stmt = $this->db->prepare("SELECT key FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            $stmt = $this->db->prepare("UPDATE settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = ?");
            $stmt->execute([$value, $key]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$key, $value]);
        }
    }

    /**
     * Get addresses eligible for auto-sync: bounce_count >= 2, excluding manually unsynced.
     * Used by cron and Sync Now. One row per original_to (latest bounce), with reason.
     *
     * @return array<array{email: string, last_updated: string, reason: string}>
     */
    public function getAddressesForAutoSync() {
        $manuallyUnsynced = array_flip($this->getManuallyUnsyncedEmails());

        $stmt = $this->db->query("
            SELECT b.original_to, b.bounce_date, b.smtp_code, b.smtp_reason, sc.description as smtp_description
            FROM bounces b
            LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
            WHERE LOWER(TRIM(b.original_to)) IN (
                SELECT LOWER(TRIM(original_to)) FROM bounces
                WHERE original_to IS NOT NULL AND TRIM(original_to) != ''
                GROUP BY LOWER(TRIM(original_to))
                HAVING COUNT(*) >= 2
            )
            ORDER BY b.bounce_date DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byEmail = [];
        foreach ($rows as $r) {
            $email = trim($r['original_to']);
            if ($email === '') {
                continue;
            }
            $emailLower = strtolower($email);
            if (isset($byEmail[$emailLower])) {
                continue;
            }
            if (isset($manuallyUnsynced[$emailLower])) {
                continue;
            }
            $reason = $this->formatReason($r['smtp_code'], $r['smtp_reason'], $r['smtp_description'] ?? '');
            $byEmail[$emailLower] = [
                'email' => $emailLower,
                'last_updated' => $r['bounce_date'],
                'reason' => $reason,
            ];
        }
        return array_values($byEmail);
    }

    /**
     * Get confirmed hard-bounce bad addresses from SQLite: one row per original_to (latest bounce),
     * with reason string. deliverability_status = 'permanent_failure'.
     * Kept for backward compatibility; auto-sync uses getAddressesForAutoSync().
     *
     * @return array<array{email: string, last_updated: string, reason: string}>
     */
    public function getHardBounceAddresses() {
        $stmt = $this->db->query("
            SELECT b.original_to, b.bounce_date, b.smtp_code, b.smtp_reason, sc.description as smtp_description
            FROM bounces b
            LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
            WHERE b.deliverability_status = 'permanent_failure'
              AND b.original_to IS NOT NULL AND TRIM(b.original_to) != ''
            ORDER BY b.bounce_date DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byEmail = [];
        foreach ($rows as $r) {
            $email = trim($r['original_to']);
            if ($email === '') {
                continue;
            }
            $emailLower = strtolower($email);
            if (isset($byEmail[$emailLower])) {
                continue;
            }
            $reason = $this->formatReason($r['smtp_code'], $r['smtp_reason'], $r['smtp_description'] ?? '');
            $byEmail[$emailLower] = [
                'email' => $emailLower,
                'last_updated' => $r['bounce_date'],
                'reason' => $reason,
            ];
        }
        return array_values($byEmail);
    }

    private function formatReason($code, $reason, $description) {
        $parts = array_filter([$code, $reason ?: $description]);
        return implode(' - ', $parts) ?: 'Hard bounce';
    }

    /**
     * Get latest bounce per email for given addresses (any deliverability). Used for manual "sync selected" from Bounce Log.
     *
     * @param string[] $emails
     * @return array<array{email: string, last_updated: string, reason: string}>
     */
    public function getAddressesForSync(array $emails) {
        $emails = array_unique(array_filter(array_map(function ($e) {
            return strtolower(trim($e));
        }, $emails)));
        if (empty($emails)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $this->db->prepare("
            SELECT b.original_to, b.bounce_date, b.smtp_code, b.smtp_reason, sc.description as smtp_description
            FROM bounces b
            LEFT JOIN smtp_codes sc ON b.smtp_code = sc.code
            WHERE LOWER(TRIM(b.original_to)) IN ({$placeholders})
            ORDER BY b.bounce_date DESC
        ");
        $stmt->execute(array_values($emails));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byEmail = [];
        foreach ($rows as $r) {
            $email = trim($r['original_to']);
            if ($email === '') {
                continue;
            }
            $emailLower = strtolower($email);
            if (isset($byEmail[$emailLower])) {
                continue;
            }
            $reason = $this->formatReason($r['smtp_code'], $r['smtp_reason'], $r['smtp_description'] ?? '');
            $byEmail[$emailLower] = [
                'email' => $emailLower,
                'last_updated' => $r['bounce_date'],
                'reason' => $reason,
            ];
        }
        return array_values($byEmail);
    }

    /**
     * Sync selected email addresses to MSSQL (from Bounce Log). Uses latest bounce per email.
     *
     * @param string[] $emails
     * @return array{success: int, updated: int, errors: array}
     */
    public function syncSelectedToMssql(array $emails) {
        $addresses = $this->getAddressesForSync($emails);
        if (empty($addresses)) {
            return ['success' => 0, 'updated' => 0, 'errors' => []];
        }
        $config = $this->getConfig();
        $table = trim($config['mssql_table'] ?? '');
        if ($table === '') {
            throw new \Exception("MSSQL table name is not set.");
        }
        $table = $this->quoteIdentifier($table);
        $pdo = $this->getConnection();
        $errors = [];
        $success = 0;
        foreach ($addresses as $row) {
            try {
                $this->upsertOne($pdo, $table, $row);
                $success++;
            } catch (\Throwable $e) {
                $errors[] = $row['email'] . ': ' . $e->getMessage();
            }
        }
        $this->eventLogger->log('info', "MSSQL sync (selected): {$success} address(es) synced", null, null, null);
        return ['success' => $success, 'updated' => count($addresses), 'errors' => $errors];
    }

    /**
     * Sync auto-sync-eligible addresses to MSSQL (bounce_count >= 2, excluding manually unsynced).
     * Upserts by email. Updates mssql_last_synced_at after sync.
     *
     * @return array{success: int, updated: int, errors: array}
     */
    public function syncToMssql() {
        $config = $this->getConfig();
        $table = trim($config['mssql_table'] ?? '');
        if ($table === '') {
            throw new \Exception("MSSQL table name is not set.");
        }
        $table = $this->quoteIdentifier($table);

        $addresses = $this->getAddressesForAutoSync();
        if (empty($addresses)) {
            $this->eventLogger->log('info', 'MSSQL sync: no addresses to sync (bounce_count >= 2, excluding manually unsynced)', null, null, null);
            $this->setSetting('mssql_last_synced_at', date('Y-m-d H:i:s'));
            $this->config = null;
            return ['success' => 0, 'updated' => 0, 'errors' => []];
        }

        $pdo = $this->getConnection();
        $errors = [];
        $success = 0;

        foreach ($addresses as $row) {
            try {
                $this->upsertOne($pdo, $table, $row);
                $success++;
            } catch (\Throwable $e) {
                $errors[] = $row['email'] . ': ' . $e->getMessage();
            }
        }

        $this->setSetting('mssql_last_synced_at', date('Y-m-d H:i:s'));
        $this->config = null;

        $this->eventLogger->log(
            'info',
            "MSSQL sync: {$success} address(es) synced to remote table" . (count($errors) ? ', ' . count($errors) . ' error(s)' : ''),
            null, null, null
        );
        if (!empty($errors)) {
            $this->eventLogger->log('error', 'MSSQL sync errors: ' . implode('; ', array_slice($errors, 0, 5)), null, null, null);
        }

        return ['success' => $success, 'updated' => count($addresses), 'errors' => $errors];
    }

    /**
     * Return list of email addresses currently in the remote BadAddresses table.
     *
     * @return string[]
     */
    public function getSyncedEmails() {
        $config = $this->getConfig();
        $table = trim($config['mssql_table'] ?? '');
        if ($table === '') {
            return [];
        }
        $table = $this->quoteIdentifier($table);
        $pdo = $this->getConnection();
        $stmt = $pdo->query("SELECT email FROM {$table}");
        $emails = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $email = strtolower(trim($row['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        return $emails;
    }

    /**
     * Remove one email from the remote BadAddresses table.
     *
     * @param string $email
     * @return bool True if a row was deleted
     */
    public function removeFromMssql($email) {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \Exception("Email is required");
        }
        $config = $this->getConfig();
        $table = trim($config['mssql_table'] ?? '');
        if ($table === '') {
            throw new \Exception("MSSQL table name is not set.");
        }
        $table = $this->quoteIdentifier($table);
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE email = ?");
        $stmt->execute([$email]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->eventLogger->log('info', "Removed {$email} from BadAddresses table", null, null, null);
        }
        return $deleted;
    }

    /**
     * Quote identifier for MSSQL (simple: no brackets if not needed, use brackets for safety).
     */
    private function quoteIdentifier($name) {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function upsertOne(PDO $pdo, $table, array $row) {
        $email = $row['email'];
        $last_updated = $row['last_updated'];
        $reason = $row['reason'] ?? '';
        // MSSQL reason column is typically NVARCHAR(2000); avoid overflow
        if (mb_strlen($reason) > 2000) {
            $reason = mb_substr($reason, 0, 1997) . '...';
        }

        // MSSQL MERGE: upsert by email
        $sql = "
            MERGE INTO {$table} AS t
            USING (SELECT ? AS email, ? AS last_updated, ? AS reason) AS s
            ON t.email = s.email
            WHEN MATCHED THEN
                UPDATE SET t.last_updated = s.last_updated, t.reason = s.reason
            WHEN NOT MATCHED THEN
                INSERT (email, last_updated, reason) VALUES (s.email, s.last_updated, s.reason);
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$email, $last_updated, $reason]);
    }
}
