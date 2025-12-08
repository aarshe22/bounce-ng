<?php

namespace BounceNG;

class TrustScoreCalculator {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Calculate trust score on a 1-10 scale (1 = least trusted, 10 = most trusted)
     * Uses DNS lookups, bounce patterns, and SMTP code analysis
     */
    public function calculateTrustScore($domain, $bounceData) {
        // Start with base score of 5 (neutral)
        $score = 5.0;

        // Get historical data for this domain
        $stmt = $this->db->prepare("
            SELECT bounce_count, trust_score, last_bounce_date
            FROM recipient_domains 
            WHERE domain = ?
        ");
        $stmt->execute([$domain]);
        $domainData = $stmt->fetch();

        // If domain exists, start from previous score (converted from 0-100 to 1-10)
        if ($domainData && $domainData['trust_score'] !== null) {
            $score = ($domainData['trust_score'] / 100) * 10; // Convert from 0-100 to 1-10
        }

        // DNS-based checks (no API keys required)
        $dnsScore = $this->checkDnsReputation($domain);
        $score += $dnsScore;

        // Analyze bounce type and SMTP code
        $smtpCode = $bounceData['smtp_code'] ?? '';
        $deliverabilityStatus = $bounceData['deliverability_status'] ?? 'unknown';

        // Permanent failures significantly reduce trust
        if ($deliverabilityStatus === 'permanent_failure') {
            $score -= 2.0;
        } elseif ($deliverabilityStatus === 'temporary_failure') {
            $score -= 0.5; // Temporary failures are less severe
        }

        // Specific SMTP codes affect trust differently
        $codeNum = is_numeric($smtpCode) ? (int)$smtpCode : 0;
        if ($codeNum >= 550 && $codeNum <= 559) {
            $score -= 2.5; // Permanent failures (mailbox unavailable, etc.)
        } elseif ($codeNum >= 551 && $codeNum <= 553) {
            $score -= 2.0; // User/domain issues
        } elseif ($codeNum >= 450 && $codeNum <= 459) {
            $score -= 0.8; // Temporary failures
        } elseif ($codeNum >= 250 && $codeNum <= 259) {
            $score += 0.5; // Success codes (shouldn't happen in bounces, but if present, it's good)
        }

        // Spam score affects trust
        $spamScore = $bounceData['spam_score'] ?? 0;
        if ($spamScore > 50) {
            $score -= (($spamScore - 50) / 50) * 1.5; // Scale spam score impact
        }

        // Consider bounce frequency and patterns
        if ($domainData && $domainData['bounce_count'] > 0) {
            $bounceCount = $domainData['bounce_count'];
            
            // Calculate bounce rate impact (more bounces = lower trust)
            // Use logarithmic scale to prevent extreme penalties
            if ($bounceCount > 100) {
                $score -= 2.0;
            } elseif ($bounceCount > 50) {
                $score -= 1.2;
            } elseif ($bounceCount > 20) {
                $score -= 0.8;
            } elseif ($bounceCount > 10) {
                $score -= 0.4;
            } elseif ($bounceCount > 5) {
                $score -= 0.2;
            }

            // Check if bounces are recent (recent bounces are more concerning)
            if ($domainData['last_bounce_date']) {
                $lastBounce = new \DateTime($domainData['last_bounce_date']);
                $now = new \DateTime();
                $daysSinceLastBounce = $now->diff($lastBounce)->days;
                
                if ($daysSinceLastBounce < 1) {
                    $score -= 0.5; // Very recent bounce
                } elseif ($daysSinceLastBounce < 7) {
                    $score -= 0.3; // Recent bounce
                } elseif ($daysSinceLastBounce > 90) {
                    $score += 0.3; // Old bounce, might be improving
                }
            }
        }

        // Check bounce pattern (all permanent vs mixed)
        if ($domainData) {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN deliverability_status = 'permanent_failure' THEN 1 ELSE 0 END) as permanent
                FROM bounces
                WHERE recipient_domain = ?
            ");
            $stmt->execute([$domain]);
            $pattern = $stmt->fetch();
            
            if ($pattern && $pattern['total'] > 0) {
                $permanentRate = ($pattern['permanent'] / $pattern['total']) * 100;
                if ($permanentRate > 80) {
                    $score -= 1.5; // Mostly permanent failures
                } elseif ($permanentRate > 50) {
                    $score -= 0.8; // Majority permanent failures
                }
            }
        }

        // Ensure score stays within 1-10 bounds
        $score = max(1.0, min(10.0, $score));

        // Convert back to 0-100 scale for database storage
        return (int)round(($score / 10) * 100);
    }

    /**
     * Check DNS-based reputation (no API keys required)
     * Returns adjustment to score (-1 to +1 range)
     */
    private function checkDnsReputation($domain) {
        $adjustment = 0.0;

        try {
            // Check MX records (domains with valid MX are more legitimate)
            $mxRecords = @dns_get_record($domain, DNS_MX);
            if ($mxRecords && count($mxRecords) > 0) {
                $adjustment += 0.3; // Has MX records
            } else {
                $adjustment -= 0.2; // No MX records (suspicious)
            }

            // Check for SPF record
            $txtRecords = @dns_get_record($domain, DNS_TXT);
            if ($txtRecords) {
                $hasSpf = false;
                foreach ($txtRecords as $record) {
                    if (isset($record['txt']) && stripos($record['txt'], 'v=spf1') !== false) {
                        $hasSpf = true;
                        break;
                    }
                }
                if ($hasSpf) {
                    $adjustment += 0.2; // Has SPF record (good email hygiene)
                }
            }

            // Check for DMARC record
            $dmarcRecords = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
            if ($dmarcRecords && count($dmarcRecords) > 0) {
                $adjustment += 0.2; // Has DMARC record (excellent email hygiene)
            }

            // Check if domain resolves (basic validation)
            $aRecords = @dns_get_record($domain, DNS_A);
            $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
            if (($aRecords && count($aRecords) > 0) || ($aaaaRecords && count($aaaaRecords) > 0)) {
                $adjustment += 0.1; // Domain resolves
            } else {
                $adjustment -= 0.3; // Domain doesn't resolve (very suspicious)
            }

        } catch (\Exception $e) {
            // DNS lookup failed - don't penalize, just skip DNS checks
            error_log("DNS lookup failed for {$domain}: " . $e->getMessage());
        }

        return $adjustment;
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
