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
        $keys = ['mssql_server', 'mssql_port', 'mssql_database', 'mssql_table', 'mssql_username', 'mssql_password'];
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
     */
    private function buildDsn($server, $port, $database) {
        $port = (int) $port ?: 1433;
        $server = trim($server);
        $database = trim($database);
        $drivers = PDO::getAvailableDrivers();
        if (in_array('sqlsrv', $drivers, true)) {
            return "sqlsrv:Server=" . $server . "," . $port . ";Database=" . $database;
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

        $dsn = $this->buildDsn($server, $port, $database);
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
     * Get confirmed hard-bounce bad addresses from SQLite: one row per original_to (latest bounce),
     * with reason string. deliverability_status = 'permanent_failure'.
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
            if (isset($byEmail[$email])) {
                continue;
            }
            $reason = $this->formatReason($r['smtp_code'], $r['smtp_reason'], $r['smtp_description'] ?? '');
            $byEmail[$email] = [
                'email' => $email,
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
     * Sync hard-bounce addresses to MSSQL. Upserts by email (no duplicates; updates if exists).
     * Table must have columns: email (PK), last_updated, reason.
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

        $addresses = $this->getHardBounceAddresses();
        if (empty($addresses)) {
            $this->eventLogger->log('info', 'MSSQL sync: no hard-bounce addresses to sync', null, null, null);
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
     * Quote identifier for MSSQL (simple: no brackets if not needed, use brackets for safety).
     */
    private function quoteIdentifier($name) {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function upsertOne(PDO $pdo, $table, array $row) {
        $email = $row['email'];
        $last_updated = $row['last_updated'];
        $reason = $row['reason'] ?? '';

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
