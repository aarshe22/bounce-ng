<?php

namespace BounceNG;

/**
 * Runs and tracks database migrations (SQLite schema updates).
 * Migrations are defined here; apply via Control Panel → Migrations or API.
 */
class Migrations {
    private $db;

    /** @var array<int, array{id: string, name: string, description: string, sql: string|string[]}> */
    private static $definitions = [
        [
            'id'          => '20250213_events_log_index',
            'name'        => 'Events log created_at index',
            'description' => 'Ensure index on events_log.created_at for faster event log queries.',
            'sql'         => ['CREATE INDEX IF NOT EXISTS idx_events_log_created_at ON events_log(created_at)'],
        ],
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Return migration definitions (id, name, description). Does not include SQL for security.
     */
    public function getDefinitions() {
        return array_map(function ($m) {
            return [
                'id'          => $m['id'],
                'name'        => $m['name'],
                'description' => $m['description'],
            ];
        }, self::$definitions);
    }

    /**
     * Return set of applied migration IDs.
     * @return array<string>
     */
    public function getAppliedIds() {
        $this->ensureMigrationsTable();
        $stmt = $this->db->query("SELECT id FROM migrations");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows, 'id');
    }

    /**
     * List all migrations with applied_at (null if not applied).
     * @return array<array{id: string, name: string, description: string, applied_at: string|null}>
     */
    public function listWithStatus() {
        $defs = $this->getDefinitions();
        $applied = $this->getAppliedIds();
        $appliedAt = [];
        if (!empty($applied)) {
            $placeholders = implode(',', array_fill(0, count($applied), '?'));
            $stmt = $this->db->prepare("SELECT id, applied_at FROM migrations WHERE id IN ({$placeholders})");
            $stmt->execute(array_values($applied));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $appliedAt[$row['id']] = $row['applied_at'];
            }
        }
        $result = [];
        foreach ($defs as $d) {
            $result[] = [
                'id'          => $d['id'],
                'name'        => $d['name'],
                'description' => $d['description'],
                'applied_at'  => $appliedAt[$d['id']] ?? null,
            ];
        }
        return $result;
    }

    /**
     * Apply a migration by ID. Idempotent migrations (e.g. CREATE INDEX IF NOT EXISTS) are safe to run once.
     * @param string $id Migration ID
     * @throws \Exception if migration not found or already applied or SQL fails
     */
    public function apply($id) {
        $id = trim($id);
        if ($id === '') {
            throw new \Exception('Migration ID is required.');
        }
        $this->ensureMigrationsTable();
        $applied = $this->getAppliedIds();
        if (in_array($id, $applied, true)) {
            throw new \Exception("Migration already applied: {$id}");
        }
        $def = null;
        foreach (self::$definitions as $d) {
            if ($d['id'] === $id) {
                $def = $d;
                break;
            }
        }
        if ($def === null) {
            throw new \Exception("Unknown migration: {$id}");
        }
        $sqlList = is_array($def['sql']) ? $def['sql'] : [$def['sql']];
        foreach ($sqlList as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            $this->db->exec($sql);
        }
        $stmt = $this->db->prepare("INSERT INTO migrations (id, applied_at) VALUES (?, datetime('now'))");
        $stmt->execute([$id]);
    }

    private function ensureMigrationsTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id TEXT PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
}
