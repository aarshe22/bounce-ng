<?php

namespace BounceNG;

use Exception;

class EmailParser {
    private $rawEmail;
    private $headers;
    private $body;
    private $parsedData;

    public function __construct($rawEmail) {
        $this->rawEmail = $rawEmail;
        $this->parse();
    }

    private function parse() {
        // Split headers and body
        $parts = preg_split("/\r?\n\r?\n/", $this->rawEmail, 2);
        $this->headers = $parts[0] ?? '';
        $this->body = $parts[1] ?? '';

        // Parse headers
        $this->parsedData = $this->parseHeaders($this->headers);
        $this->parsedData['headers'] = $this->headers; // Store raw headers
        
        // Parse body for bounce information
        $this->parseBody($this->body);
        $this->parsedData['body'] = $this->body; // Store raw body
    }

    private function parseHeaders($headers) {
        $parsed = [];
        $lines = explode("\n", $headers);
        
        foreach ($lines as $line) {
            if (preg_match('/^([^:]+):\s*(.+)$/i', trim($line), $matches)) {
                $key = strtolower(trim($matches[1]));
                $value = $this->decodeHeader($matches[2]);
                
                if (isset($parsed[$key])) {
                    if (!is_array($parsed[$key])) {
                        $parsed[$key] = [$parsed[$key]];
                    }
                    $parsed[$key][] = $value;
                } else {
                    $parsed[$key] = $value;
                }
            }
        }

        return $parsed;
    }

    private function decodeHeader($header) {
        // Decode MIME encoded headers
        if (preg_match_all('/=\?([^?]+)\?([QB])\?([^?]+)\?=/i', $header, $matches, PREG_SET_ORDER)) {
            $decoded = $header;
            foreach ($matches as $match) {
                $charset = $match[1];
                $encoding = strtoupper($match[2]);
                $text = $match[3];
                
                if ($encoding === 'Q') {
                    $text = quoted_printable_decode(str_replace('_', ' ', $text));
                } elseif ($encoding === 'B') {
                    $text = base64_decode($text);
                }
                
                if (function_exists('mb_convert_encoding')) {
                    $text = mb_convert_encoding($text, 'UTF-8', $charset);
                }
                
                $decoded = str_replace($match[0], $text, $decoded);
            }
            return $decoded;
        }
        return $header;
    }

    private function parseBody($body) {
        // Decode body if it's MIME encoded (base64, quoted-printable, etc.)
        $decodedBody = $this->decodeBody($body);
        
        // Try to extract original email information from decoded body
        $this->extractOriginalEmailInfo($decodedBody);
        
        // Extract SMTP codes from decoded body
        $this->extractSmtpCodes($decodedBody);
        
        // Calculate spam score from headers
        $this->calculateSpamScore();
        
        // Determine deliverability status
        $this->determineDeliverabilityStatus();
    }
    
    private function decodeBody($body) {
        // Check if body is MIME multipart
        if (preg_match('/Content-Type:\s*multipart\/([^;]+)/i', $this->headers, $matches)) {
            return $this->parseMultipartBody($body);
        }
        
        // Check for Content-Transfer-Encoding
        $encoding = null;
        if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $this->headers, $matches)) {
            $encoding = strtolower(trim($matches[1]));
        }
        
        // Decode based on encoding
        if ($encoding === 'base64') {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        } elseif ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }
        
        // Check for charset and convert if needed
        if (preg_match('/charset=([^;\s]+)/i', $this->headers, $matches)) {
            $charset = strtolower(trim($matches[1]));
            if ($charset !== 'utf-8' && function_exists('mb_convert_encoding')) {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            }
        }
        
        return $body;
    }
    
    private function parseMultipartBody($body) {
        // Extract boundary
        if (!preg_match('/boundary=([^;\r\n]+)/i', $this->headers, $matches)) {
            return $body; // Can't parse without boundary
        }
        
        $boundary = trim($matches[1], '"');
        $parts = explode('--' . $boundary, $body);
        $decodedParts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') {
                continue; // Skip empty parts and final boundary
            }
            
            // Split part header and body
            $partParts = preg_split("/\r?\n\r?\n/", $part, 2);
            $partHeader = $partParts[0] ?? '';
            $partBody = $partParts[1] ?? '';
            
            // Check Content-Type
            $contentType = '';
            if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeader, $ctMatches)) {
                $contentType = strtolower(trim($ctMatches[1]));
            }
            
            // Check encoding
            $encoding = '';
            if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $partHeader, $encMatches)) {
                $encoding = strtolower(trim($encMatches[1]));
            }
            
            // Decode part body
            if ($encoding === 'base64') {
                $partBody = base64_decode($partBody, true) ?: $partBody;
            } elseif ($encoding === 'quoted-printable') {
                $partBody = quoted_printable_decode($partBody);
            }
            
            // Check charset
            $charset = 'utf-8';
            if (preg_match('/charset=([^;\s]+)/i', $partHeader, $csMatches)) {
                $charset = strtolower(trim($csMatches[1]));
            }
            
            // Convert charset if needed
            if ($charset !== 'utf-8' && function_exists('mb_convert_encoding')) {
                $partBody = mb_convert_encoding($partBody, 'UTF-8', $charset);
            }
            
            // Prefer text/plain, but collect all parts
            if (strpos($contentType, 'text/plain') !== false || empty($decodedParts)) {
                $decodedParts[] = $partBody;
            }
        }
        
        // Return concatenated decoded parts
        return implode("\n\n", $decodedParts);
    }

    private function extractOriginalEmailInfo($body) {
        // Look for original email headers in the body
        $patterns = [
            '/Original-Recipient:\s*rfc822;\s*([^\s]+)/i',
            '/Final-Recipient:\s*rfc822;\s*([^\s]+)/i',
            '/X-Original-To:\s*([^\r\n]+)/i',
            '/To:\s*([^\r\n]+)/i',
        ];

        $originalTo = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $originalTo = trim($matches[1]);
                break;
            }
        }

        // Also check headers
        if (!$originalTo) {
            $headers = $this->parsedData;
            if (isset($headers['x-original-to'])) {
                $originalTo = is_array($headers['x-original-to']) 
                    ? $headers['x-original-to'][0] 
                    : $headers['x-original-to'];
            } elseif (isset($headers['original-recipient'])) {
                $originalTo = is_array($headers['original-recipient']) 
                    ? $headers['original-recipient'][0] 
                    : $headers['original-recipient'];
                // Remove rfc822; prefix if present
                $originalTo = preg_replace('/^rfc822;\s*/i', '', $originalTo);
            }
        }

        $this->parsedData['original_to'] = $originalTo;

        // Extract original CC addresses - try multiple patterns and locations
        $ccAddresses = [];
        
        // Pattern 1: Look for CC in embedded original email headers (common in bounce messages)
        // Many bounce emails include the original message headers in the body
        $embeddedHeaderPatterns = [
            // Look for original email headers embedded in the bounce body
            '/^CC:\s*([^\r\n]+)/im',
            '/^Cc:\s*([^\r\n]+)/im',
            '/^X-Original-CC:\s*([^\r\n]+)/im',
            '/^Original-CC:\s*([^\r\n]+)/im',
            '/^Resent-CC:\s*([^\r\n]+)/im',
            '/^X-Envelope-CC:\s*([^\r\n]+)/im',
            // Look for CC in quoted original message sections
            '/-----Original Message-----[\s\S]*?CC:\s*([^\r\n]+)/i',
            '/From:[\s\S]*?CC:\s*([^\r\n]+)/i',
            '/To:[\s\S]*?CC:\s*([^\r\n]+)/i',
        ];
        
        foreach ($embeddedHeaderPatterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $ccList) {
                    $ccList = trim($ccList);
                    if (!empty($ccList)) {
                        $parsed = $this->parseEmailList($ccList);
                        $ccAddresses = array_merge($ccAddresses, $parsed);
                    }
                }
            }
        }
        
        // Pattern 2: Standard CC header patterns in body
        $patterns = [
            '/X-Original-CC:\s*([^\r\n]+)/i',
            '/Original-CC:\s*([^\r\n]+)/i',
            '/CC:\s*([^\r\n]+)/i',
            '/Cc:\s*([^\r\n]+)/i',
            '/Resent-CC:\s*([^\r\n]+)/i',
            '/X-Envelope-CC:\s*([^\r\n]+)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $ccList) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
        }
        
        // Pattern 3: Check email headers
        $headerFields = ['x-original-cc', 'original-cc', 'cc', 'resent-cc', 'x-envelope-cc'];
        foreach ($headerFields as $field) {
            if (isset($this->parsedData[$field])) {
                $ccList = is_array($this->parsedData[$field]) 
                    ? $this->parsedData[$field][0] 
                    : $this->parsedData[$field];
                $parsed = $this->parseEmailList($ccList);
                $ccAddresses = array_merge($ccAddresses, $parsed);
            }
        }
        
        // Pattern 4: Look for email addresses in the body that might be CC addresses
        // This is a fallback - look for email patterns near "CC" or "Cc" keywords
        if (empty($ccAddresses)) {
            if (preg_match_all('/(?:CC|Cc|cc):\s*([^\r\n]+)/i', $body, $matches)) {
                foreach ($matches[1] as $ccList) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
        }
        
        // Remove duplicates and empty values
        $ccAddresses = array_unique(array_filter($ccAddresses));
        $ccAddresses = array_values($ccAddresses); // Re-index

        $this->parsedData['original_cc'] = $ccAddresses;

        // Extract original subject
        if (preg_match('/X-Original-Subject:\s*([^\r\n]+)/i', $body, $matches)) {
            $this->parsedData['original_subject'] = $this->decodeHeader($matches[1]);
        } elseif (isset($this->parsedData['subject'])) {
            // Sometimes the subject contains the original subject
            $subject = is_array($this->parsedData['subject']) 
                ? $this->parsedData['subject'][0] 
                : $this->parsedData['subject'];
            $this->parsedData['original_subject'] = $subject;
        }

        // Extract original sent date
        if (preg_match('/X-Original-Date:\s*([^\r\n]+)/i', $body, $matches)) {
            $this->parsedData['original_sent_date'] = $this->parseDate($matches[1]);
        } elseif (isset($this->parsedData['date'])) {
            $date = is_array($this->parsedData['date']) 
                ? $this->parsedData['date'][0] 
                : $this->parsedData['date'];
            $this->parsedData['original_sent_date'] = $this->parseDate($date);
        }
    }

    private function parseEmailList($emailList) {
        $emails = [];
        // Split by comma and clean up
        $parts = preg_split('/[,\s]+/', $emailList);
        foreach ($parts as $part) {
            $part = trim($part);
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $part;
            }
        }
        return $emails;
    }

    private function extractSmtpCodes($body) {
        // Look for SMTP status codes
        $patterns = [
            '/(\d{3})\s+([^\r\n]+)/',  // Standard SMTP response
            '/Status:\s*(\d{3})/i',
            '/Diagnostic-Code:\s*[^\r\n]*?(\d{3})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $this->parsedData['smtp_code'] = $matches[1];
                if (isset($matches[2])) {
                    $this->parsedData['smtp_reason'] = trim($matches[2]);
                }
                break;
            }
        }

        // Also check for diagnostic code
        if (preg_match('/Diagnostic-Code:\s*([^\r\n]+)/i', $body, $matches)) {
            $this->parsedData['smtp_reason'] = trim($matches[1]);
        }
    }

    private function calculateSpamScore() {
        $score = 0;
        $headers = $this->parsedData;

        // Check SPF
        if (isset($headers['received-spf'])) {
            $spf = is_array($headers['received-spf']) 
                ? $headers['received-spf'][0] 
                : $headers['received-spf'];
            if (stripos($spf, 'pass') !== false) {
                $score -= 5;
            } elseif (stripos($spf, 'fail') !== false) {
                $score += 20;
            }
        }

        // Check DKIM
        if (isset($headers['dkim-signature'])) {
            $score -= 5;
        }

        // Check DMARC
        if (isset($headers['authentication-results'])) {
            $auth = is_array($headers['authentication-results']) 
                ? $headers['authentication-results'][0] 
                : $headers['authentication-results'];
            if (stripos($auth, 'pass') !== false) {
                $score -= 5;
            }
        }

        // Check for suspicious patterns
        if (isset($headers['x-spam-status'])) {
            $spamStatus = is_array($headers['x-spam-status']) 
                ? $headers['x-spam-status'][0] 
                : $headers['x-spam-status'];
            if (stripos($spamStatus, 'yes') !== false) {
                $score += 30;
            }
        }

        $this->parsedData['spam_score'] = max(0, min(100, $score));
    }

    private function determineDeliverabilityStatus() {
        $status = 'unknown';
        $code = $this->parsedData['smtp_code'] ?? '';

        if (empty($code)) {
            $this->parsedData['deliverability_status'] = 'unknown';
            return;
        }

        $firstDigit = substr($code, 0, 1);
        switch ($firstDigit) {
            case '2':
                $status = 'delivered';
                break;
            case '4':
                $status = 'temporary_failure';
                break;
            case '5':
                $status = 'permanent_failure';
                break;
            default:
                $status = 'unknown';
        }

        $this->parsedData['deliverability_status'] = $status;
    }

    private function parseDate($dateString) {
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return date('Y-m-d H:i:s');
        }
    }

    public function isBounce() {
        // Check if this is a legitimate bounce
        $headers = $this->parsedData;
        
        // Check for bounce indicators
        $bounceIndicators = [
            'auto-replied',
            'auto-submitted',
            'returned mail',
            'undeliverable',
            'delivery failure',
            'mail delivery failed',
            'mail delivery subsystem',
        ];

        $subject = isset($headers['subject']) 
            ? (is_array($headers['subject']) ? $headers['subject'][0] : $headers['subject'])
            : '';
        $subjectLower = strtolower($subject);

        // Check if it's an auto-reply or out-of-office
        $autoReplyIndicators = [
            'out of office',
            'automatic reply',
            'auto reply',
            'vacation',
            'away from office',
        ];

        foreach ($autoReplyIndicators as $indicator) {
            if (stripos($subjectLower, $indicator) !== false) {
                return false; // Not a bounce, it's an auto-reply
            }
        }

        // Check for bounce indicators
        foreach ($bounceIndicators as $indicator) {
            if (stripos($subjectLower, $indicator) !== false) {
                return true;
            }
        }

        // Check for SMTP error codes
        if (isset($this->parsedData['smtp_code'])) {
            $code = $this->parsedData['smtp_code'];
            $firstDigit = substr($code, 0, 1);
            if (in_array($firstDigit, ['4', '5'])) {
                return true;
            }
        }

        // Check for bounce-related headers
        if (isset($headers['x-failed-recipients']) || 
            isset($headers['x-original-to']) ||
            isset($headers['original-recipient'])) {
            return true;
        }

        return false;
    }

    public function getParsedData() {
        return $this->parsedData;
    }

    public function getOriginalTo() {
        return $this->parsedData['original_to'] ?? null;
    }

    public function getOriginalCc() {
        return $this->parsedData['original_cc'] ?? [];
    }

    public function getOriginalSubject() {
        return $this->parsedData['original_subject'] ?? null;
    }

    public function getOriginalSentDate() {
        return $this->parsedData['original_sent_date'] ?? null;
    }

    public function getSmtpCode() {
        return $this->parsedData['smtp_code'] ?? null;
    }

    public function getSmtpReason() {
        return $this->parsedData['smtp_reason'] ?? null;
    }

    public function getSpamScore() {
        return $this->parsedData['spam_score'] ?? 0;
    }

    public function getDeliverabilityStatus() {
        return $this->parsedData['deliverability_status'] ?? 'unknown';
    }

    public function getRecipientDomain() {
        $originalTo = $this->getOriginalTo();
        if ($originalTo && filter_var($originalTo, FILTER_VALIDATE_EMAIL)) {
            return substr(strrchr($originalTo, "@"), 1);
        }
        return null;
    }
}

