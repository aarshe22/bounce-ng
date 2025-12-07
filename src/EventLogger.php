<?php

namespace BounceNG;

class EventLogger {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function log($severity, $message, $userId = null, $mailboxId = null, $bounceId = null, $metadata = null) {
        try {
            $sql = "
                INSERT INTO events_log (event_type, severity, message, user_id, mailbox_id, bounce_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $eventType = $this->determineEventType($severity, $message);
            $metadataJson = $metadata ? json_encode($metadata) : null;
            
            $params = [
                $eventType,
                $severity,
                $message,
                $userId,
                $mailboxId,
                $bounceId,
                $metadataJson
            ];
            
            // Don't log SQL for events_log inserts to avoid recursion
            // SQL logging is only for other tables (bounces, notifications_queue, etc.)
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            // Verify the log was written
            $lastId = $this->db->lastInsertId();
            if (!$lastId) {
                error_log("EventLogger WARNING: Log written but lastInsertId returned 0. Message: " . substr($message, 0, 100));
            }
        } catch (\Exception $e) {
            // Fallback to error_log if database write fails
            error_log("EventLogger ERROR: Failed to write log - " . $e->getMessage() . " | Message: " . $message);
            error_log("EventLogger ERROR: SQL: " . ($sql ?? 'N/A') . " | Params: " . json_encode($params ?? []));
        }
    }

    private function determineEventType($severity, $message) {
        // Determine event type based on message content
        $messageLower = strtolower($message);
        
        if (stripos($messageLower, 'bounce') !== false) {
            return 'bounce_processed';
        } elseif (stripos($messageLower, 'mailbox') !== false) {
            return 'mailbox_operation';
        } elseif (stripos($messageLower, 'notification') !== false) {
            return 'notification';
        } elseif (stripos($messageLower, 'user') !== false) {
            return 'user_activity';
        } elseif (stripos($messageLower, 'error') !== false || $severity === 'error') {
            return 'error';
        } else {
            return 'system';
        }
    }

    public function getEvents($limit = 100, $filters = []) {
        $where = [];
        $params = [];

        if (!empty($filters['severity'])) {
            $where[] = "severity = ?";
            $params[] = $filters['severity'];
        }

        if (!empty($filters['event_type'])) {
            $where[] = "event_type = ?";
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['mailbox_id'])) {
            $where[] = "mailbox_id = ?";
            $params[] = $filters['mailbox_id'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "
            SELECT * FROM events_log 
            {$whereClause}
            ORDER BY id DESC 
            LIMIT ?
        ";
        
        $params[] = $limit;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $results = $stmt->fetchAll();
            
            // Log if we're getting events (for debugging)
            if (count($results) > 0) {
                error_log("EventLogger: Retrieved " . count($results) . " events from database");
            }
            
            return $results;
        } catch (\Exception $e) {
            error_log("EventLogger ERROR: Failed to get events - " . $e->getMessage());
            error_log("EventLogger ERROR: SQL: {$sql}, Params: " . json_encode($params));
            return [];
        }
    }
}

