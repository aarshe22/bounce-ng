<?php

namespace BounceNG;

class TrustScoreCalculator {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function calculateTrustScore($domain, $bounceData) {
        // Start with base score
        $score = 50;

        // Get historical data for this domain
        $stmt = $this->db->prepare("
            SELECT bounce_count, trust_score 
            FROM recipient_domains 
            WHERE domain = ?
        ");
        $stmt->execute([$domain]);
        $domainData = $stmt->fetch();

        if ($domainData) {
            $score = $domainData['trust_score'];
        }

        // Adjust based on bounce type
        $smtpCode = $bounceData['smtp_code'] ?? '';
        $deliverabilityStatus = $bounceData['deliverability_status'] ?? 'unknown';

        // Permanent failures reduce trust more
        if ($deliverabilityStatus === 'permanent_failure') {
            $score -= 10;
        } elseif ($deliverabilityStatus === 'temporary_failure') {
            $score -= 2;
        }

        // Specific SMTP codes affect trust differently
        if (in_array($smtpCode, ['550', '551', '552', '553', '554', '555'])) {
            $score -= 15; // Permanent failures
        } elseif (in_array($smtpCode, ['450', '451', '452'])) {
            $score -= 3; // Temporary failures
        }

        // Spam score affects trust
        $spamScore = $bounceData['spam_score'] ?? 0;
        if ($spamScore > 50) {
            $score -= ($spamScore - 50) / 5;
        }

        // Consider bounce frequency
        if ($domainData && $domainData['bounce_count'] > 0) {
            // More bounces = lower trust
            $bounceCount = $domainData['bounce_count'];
            if ($bounceCount > 100) {
                $score -= 20;
            } elseif ($bounceCount > 50) {
                $score -= 10;
            } elseif ($bounceCount > 10) {
                $score -= 5;
            }
        }

        // Ensure score stays within bounds
        $score = max(0, min(100, $score));

        return (int)round($score);
    }

    public function updateDomainTrustScore($domain, $bounceData) {
        $trustScore = $this->calculateTrustScore($domain, $bounceData);

        // Check if domain exists
        $stmt = $this->db->prepare("SELECT id, bounce_count FROM recipient_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE recipient_domains SET
                    bounce_count = bounce_count + 1,
                    trust_score = ?,
                    last_bounce_date = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE domain = ?
            ");
            $stmt->execute([$trustScore, $domain]);
        } else {
            // Insert new
            $stmt = $this->db->prepare("
                INSERT INTO recipient_domains (domain, bounce_count, trust_score, last_bounce_date, updated_at)
                VALUES (?, 1, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$domain, $trustScore]);
        }

        return $trustScore;
    }
}

