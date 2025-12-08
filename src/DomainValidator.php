<?php

namespace BounceNG;

class DomainValidator {
    /**
     * Validate a domain name
     * Returns array with 'valid' => bool, 'reason' => string
     */
    public static function validateDomain($domain) {
        if (empty($domain)) {
            return ['valid' => false, 'reason' => 'Empty domain'];
        }

        // Basic format validation
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*\.[a-z]{2,}$/i', $domain)) {
            return ['valid' => false, 'reason' => 'Invalid format'];
        }

        // Check domain length (max 253 characters)
        if (strlen($domain) > 253) {
            return ['valid' => false, 'reason' => 'Domain too long'];
        }

        // Check for valid TLD (at least 2 characters, max 63)
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return ['valid' => false, 'reason' => 'No TLD'];
        }

        $tld = end($parts);
        if (strlen($tld) < 2 || strlen($tld) > 63) {
            return ['valid' => false, 'reason' => 'Invalid TLD'];
        }

        // Check for common TLDs (basic validation)
        $commonTlds = ['com', 'org', 'net', 'edu', 'gov', 'mil', 'int', 'co', 'io', 'ca', 'uk', 'au', 'de', 'fr', 'jp', 'cn', 'in', 'br', 'ru', 'mx', 'nl', 'se', 'no', 'dk', 'fi', 'pl', 'it', 'es', 'pt', 'gr', 'ie', 'nz', 'za', 'sg', 'hk', 'tw', 'kr', 'th', 'ph', 'id', 'my', 'vn', 'us', 'info', 'biz', 'name', 'pro', 'coop', 'aero', 'museum', 'mobi', 'asia', 'tel', 'travel', 'xxx', 'jobs', 'me', 'tv', 'cc', 'ws', 'bz', 'nu', 'tk', 'ml', 'ga', 'cf', 'gq'];
        if (!in_array(strtolower($tld), $commonTlds)) {
            // Not a common TLD, but might still be valid - check DNS
        }

        // DNS validation - check if domain resolves
        // Use a timeout to avoid hanging on slow DNS lookups
        $hasDns = false;
        try {
            // Set a timeout for DNS lookups (2 seconds)
            $originalTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', 2);
            
            // Check for A record first (most common)
            $aRecords = @dns_get_record($domain, DNS_A);
            if ($aRecords && count($aRecords) > 0) {
                $hasDns = true;
            } else {
                // Check for MX record (some domains only have MX, no A)
                $mxRecords = @dns_get_record($domain, DNS_MX);
                if ($mxRecords && count($mxRecords) > 0) {
                    $hasDns = true;
                } else {
                    // Check for AAAA record (IPv6)
                    $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
                    if ($aaaaRecords && count($aaaaRecords) > 0) {
                        $hasDns = true;
                    }
                }
            }
            
            // Restore original timeout
            ini_set('default_socket_timeout', $originalTimeout);
        } catch (\Exception $e) {
            // DNS lookup failed - assume invalid
            $hasDns = false;
        }

        if (!$hasDns) {
            return ['valid' => false, 'reason' => 'Domain does not resolve (no DNS records)'];
        }

        // Additional validation: check if it's a known typo pattern
        // This is a simple heuristic - could be expanded
        $typoPatterns = [
            '/^[a-z]{1,2}[a-z]{2,}$/i', // Very short domains (like "shaaw")
        ];
        
        $domainWithoutTld = substr($domain, 0, strrpos($domain, '.'));
        if (strlen($domainWithoutTld) < 3) {
            return ['valid' => false, 'reason' => 'Suspiciously short domain name'];
        }

        return ['valid' => true, 'reason' => 'Valid domain'];
    }

    /**
     * Check if domain is likely a typo
     * Returns array with 'likely_typo' => bool, 'suggestions' => array
     */
    public static function checkTypo($domain) {
        $likelyTypo = false;
        $suggestions = [];

        // Check if domain doesn't resolve
        $validation = self::validateDomain($domain);
        if (!$validation['valid']) {
            $likelyTypo = true;
        }

        // Check for common typo patterns
        $domainWithoutTld = substr($domain, 0, strrpos($domain, '.'));
        $tld = substr($domain, strrpos($domain, '.') + 1);

        // Very short domain names are often typos
        if (strlen($domainWithoutTld) < 4) {
            $likelyTypo = true;
        }

        // Check for repeated characters (common in typos)
        if (preg_match('/(.)\1{2,}/', $domainWithoutTld)) {
            $likelyTypo = true;
        }

        // Check for common TLDs that might be typos
        $commonDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com'];
        foreach ($commonDomains as $common) {
            $commonWithoutTld = substr($common, 0, strrpos($common, '.'));
            if (levenshtein($domainWithoutTld, $commonWithoutTld) <= 2 && $tld === substr($common, strrpos($common, '.') + 1)) {
                $likelyTypo = true;
                $suggestions[] = $common;
            }
        }

        return [
            'likely_typo' => $likelyTypo,
            'suggestions' => $suggestions,
            'validation' => $validation
        ];
    }
}

