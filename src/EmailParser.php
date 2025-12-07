<?php

namespace BounceNG;

use Exception;

class EmailParser {
    private $rawEmail;
    private $headers;
    private $body;
    private $parsedData;
    private $allDecodedParts = []; // Store all decoded parts for searching

    public function __construct($rawEmail) {
        $this->rawEmail = $rawEmail;
        $this->parse();
    }

    private function parse() {
        // Normalize line endings
        $this->rawEmail = str_replace(["\r\n", "\r"], "\n", $this->rawEmail);
        $this->rawEmail = str_replace("\n", "\r\n", $this->rawEmail);
        
        // Split headers and body
        $parts = preg_split("/\r?\n\r?\n/", $this->rawEmail, 2);
        $this->headers = $parts[0] ?? '';
        $this->body = $parts[1] ?? '';

        // Parse headers (handle continuation lines)
        $this->parsedData = $this->parseHeaders($this->headers);
        $this->parsedData['headers'] = $this->headers; // Store raw headers
        
        // Fully decode and parse body - this is critical for finding CC addresses
        $this->allDecodedParts = $this->fullyDecodeBody($this->body, $this->headers);
        
        // Extract original email information from ALL decoded parts
        $this->extractOriginalEmailInfo();
        
        // Extract SMTP codes
        $this->extractSmtpCodes();
        
        // Calculate spam score from headers
        $this->calculateSpamScore();
        
        // Determine deliverability status
        $this->determineDeliverabilityStatus();
    }

    private function parseHeaders($headers) {
        $parsed = [];
        $lines = explode("\r\n", $headers);
        $currentHeader = null;
        
        foreach ($lines as $line) {
            // Check if this is a continuation line (starts with space or tab)
            if (($line[0] === ' ' || $line[0] === "\t") && $currentHeader !== null) {
                // Handle continuation line - check if current header value is an array
                if (isset($parsed[$currentHeader])) {
                    if (is_array($parsed[$currentHeader])) {
                        // If it's an array, append to the last element
                        $lastIndex = count($parsed[$currentHeader]) - 1;
                        if ($lastIndex >= 0) {
                            $parsed[$currentHeader][$lastIndex] .= ' ' . trim($line);
                        } else {
                            $parsed[$currentHeader][] = trim($line);
                        }
                    } else {
                        // If it's a string, append to it
                        $parsed[$currentHeader] .= ' ' . trim($line);
                    }
                } else {
                    // Header doesn't exist yet, create it
                    $parsed[$currentHeader] = trim($line);
                }
                continue;
            }
            
            if (preg_match('/^([^:]+):\s*(.+)$/i', trim($line), $matches)) {
                $key = strtolower(trim($matches[1]));
                $value = $this->decodeHeader($matches[2]);
                $currentHeader = $key;
                
                if (isset($parsed[$key])) {
                    if (!is_array($parsed[$key])) {
                        $parsed[$key] = [$parsed[$key]];
                    }
                    $parsed[$key][] = $value;
                } else {
                    $parsed[$key] = $value;
                }
            } else {
                $currentHeader = null;
            }
        }

        return $parsed;
    }

    private function decodeHeader($header) {
        // Decode MIME encoded headers (RFC 2047)
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
                    try {
                        $text = mb_convert_encoding($text, 'UTF-8', $charset);
                    } catch (Exception $e) {
                        // Try with //IGNORE for invalid characters
                        $text = @mb_convert_encoding($text, 'UTF-8', $charset);
                    }
                }
                
                $decoded = str_replace($match[0], $text, $decoded);
            }
            return $decoded;
        }
        return $header;
    }

    /**
     * Fully decode email body, handling all MIME structures recursively
     */
    private function fullyDecodeBody($body, $headers) {
        $decodedParts = [];
        
        // Check if this is a multipart message
        if (preg_match('/Content-Type:\s*multipart\/([^;]+)/i', $headers, $matches)) {
            $multipartType = strtolower(trim($matches[1]));
            
            // Extract boundary
            $boundary = null;
            if (preg_match('/boundary=([^;\r\n]+)/i', $headers, $boundaryMatches)) {
                $boundary = trim($boundaryMatches[1], '"\'');
            }
            
            if ($boundary) {
                // Split by boundary
                $parts = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\r?\n/', $body);
                
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (empty($part) || $part === '--') {
                        continue;
                    }
                    
                    // Split part header and body
                    $partSplit = preg_split("/\r?\n\r?\n/", $part, 2);
                    $partHeader = $partSplit[0] ?? '';
                    $partBody = $partSplit[1] ?? '';
                    
                    // Check if this part is itself multipart (nested)
                    if (preg_match('/Content-Type:\s*multipart\/([^;]+)/i', $partHeader)) {
                        // Recursively decode nested multipart
                        $nestedParts = $this->fullyDecodeBody($partBody, $partHeader);
                        $decodedParts = array_merge($decodedParts, $nestedParts);
                    } else {
                        // Decode this part
                        $decodedPart = $this->decodePart($partBody, $partHeader);
                        if (!empty($decodedPart)) {
                            $decodedParts[] = $decodedPart;
                        }
                    }
                }
            }
        } else {
            // Single part message - decode it
            $decoded = $this->decodePart($body, $headers);
            if (!empty($decoded)) {
                $decodedParts[] = $decoded;
            }
        }
        
        return $decodedParts;
    }

    /**
     * Decode a single MIME part
     */
    private function decodePart($body, $headers) {
        if (empty($body)) {
            return '';
        }
        
        // Get Content-Type
        $contentType = '';
        if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $headers, $matches)) {
            $contentType = strtolower(trim($matches[1]));
        }
        
        // Handle message/rfc822 (embedded email - this is where original email headers are!)
        if (strpos($contentType, 'message/rfc822') !== false || strpos($contentType, 'message/rfc822-headers') !== false) {
            // This is an embedded email - parse it fully
            $embeddedParts = preg_split("/\r?\n\r?\n/", $body, 2);
            $embeddedHeaders = $embeddedParts[0] ?? '';
            $embeddedBody = $embeddedParts[1] ?? '';
            
            // Parse embedded headers
            $embeddedParsed = $this->parseHeaders($embeddedHeaders);
            
            // Decode embedded body recursively
            $embeddedDecoded = $this->fullyDecodeBody($embeddedBody, $embeddedHeaders);
            
            // Return embedded headers + body for searching
            return $embeddedHeaders . "\r\n\r\n" . implode("\r\n\r\n", $embeddedDecoded);
        }
        
        // Get Content-Transfer-Encoding
        $encoding = '';
        if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $headers, $matches)) {
            $encoding = strtolower(trim($matches[1]));
        }
        
        // Decode based on transfer encoding
        switch ($encoding) {
            case 'base64':
                $decoded = base64_decode($body, true);
                if ($decoded === false) {
                    // Try without strict mode
                    $decoded = base64_decode($body);
                }
                $body = $decoded !== false ? $decoded : $body;
                break;
                
            case 'quoted-printable':
                $body = quoted_printable_decode($body);
                break;
                
            case '7bit':
            case '8bit':
            case 'binary':
                // No decoding needed, but ensure proper charset conversion
                break;
                
            default:
                // Try base64 if it looks like base64
                if (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $body) && strlen($body) > 20) {
                    $decoded = base64_decode($body, true);
                    if ($decoded !== false) {
                        $body = $decoded;
                    }
                }
        }
        
        // Get charset and convert to UTF-8
        $charset = 'utf-8';
        if (preg_match('/charset=([^;\s"\']+)/i', $headers, $matches)) {
            $charset = strtolower(trim($matches[1]));
            // Remove quotes
            $charset = trim($charset, '"\'');
            
            // Clean up any malformed charset values (e.g., "3dus-ascii" from quoted-printable corruption)
            // Remove any leading digits or invalid characters that might come from encoding issues
            $charset = preg_replace('/^[0-9]+/', '', $charset);
            // Remove any non-alphanumeric characters except hyphens and underscores
            $charset = preg_replace('/[^a-z0-9\-_]/', '', $charset);
            
            // If charset is empty or too short after cleaning, default to utf-8
            if (empty($charset) || strlen($charset) < 2) {
                $charset = 'utf-8';
            }
        }
        
        // Validate and normalize charset before conversion
        if ($charset !== 'utf-8' && !empty($charset) && function_exists('mb_convert_encoding')) {
            // Get list of supported encodings
            $supportedEncodings = @mb_list_encodings();
            if (!$supportedEncodings || !is_array($supportedEncodings)) {
                // If mb_list_encodings fails, use a safe default list
                $supportedEncodings = ['iso-8859-1', 'windows-1252', 'us-ascii', 'utf-8'];
            }
            $supportedEncodingsLower = array_map('strtolower', $supportedEncodings);
            
            // Common aliases mapping
            $charsetMap = [
                'windows-1252' => 'windows-1252',
                'windows1252' => 'windows-1252',
                'iso-8859-1' => 'iso-8859-1',
                'iso8859-1' => 'iso-8859-1',
                'iso88591' => 'iso-8859-1',
                'latin1' => 'iso-8859-1',
                'latin-1' => 'iso-8859-1',
                'us-ascii' => 'us-ascii',
                'usascii' => 'us-ascii',
                'ascii' => 'us-ascii',
            ];
            
            // Normalize charset
            $normalizedCharset = null;
            $charsetLower = strtolower($charset);
            
            // First check direct match in supported encodings
            if (in_array($charsetLower, $supportedEncodingsLower)) {
                $normalizedCharset = $charset;
            }
            // Check alias map
            elseif (isset($charsetMap[$charsetLower])) {
                $normalizedCharset = $charsetMap[$charsetLower];
            }
            // Try to find similar encoding by removing hyphens/underscores
            else {
                $charsetNormalized = str_replace(['-', '_'], '', $charsetLower);
                foreach ($supportedEncodingsLower as $enc) {
                    $encNormalized = str_replace(['-', '_'], '', $enc);
                    if ($encNormalized === $charsetNormalized || 
                        strpos($encNormalized, $charsetNormalized) !== false ||
                        strpos($charsetNormalized, $encNormalized) !== false) {
                        // Find the original encoding name (not normalized)
                        foreach ($supportedEncodings as $origEnc) {
                            if (strtolower($origEnc) === $enc) {
                                $normalizedCharset = $origEnc;
                                break 2;
                            }
                        }
                    }
                }
            }
            
            // Only attempt conversion if we have a valid, supported charset
            if ($normalizedCharset && $normalizedCharset !== 'utf-8') {
                // Double-check it's in the supported list
                $normalizedLower = strtolower($normalizedCharset);
                if (in_array($normalizedLower, $supportedEncodingsLower)) {
                    try {
                        $converted = @mb_convert_encoding($body, 'UTF-8', $normalizedCharset);
                        if ($converted !== false && $converted !== $body) {
                            $body = $converted;
                        }
                    } catch (\Exception $e) {
                        // Silently fail - keep original body
                    } catch (\ValueError $e) {
                        // PHP 8+ ValueError for invalid encoding
                        // Silently fail - keep original body
                    } catch (\Throwable $e) {
                        // Catch any other errors
                        // Silently fail - keep original body
                    }
                }
            }
        }
        
        return $body;
    }

    private function extractOriginalEmailInfo() {
        // Search in ALL decoded parts for original email information
        $allText = implode("\r\n\r\n", $this->allDecodedParts);
        
        // Extract the "ALL_PARTS" section if present (from MailboxMonitor)
        $allPartsSection = '';
        if (preg_match('/---ALL_PARTS---\r?\n\r?\n(.*)$/s', $this->rawEmail, $matches)) {
            $allPartsSection = $matches[1];
        }
        
        // Also search in raw email and headers
        $searchTexts = [
            'raw_email' => $this->rawEmail,
            'raw_headers' => $this->headers,
            'decoded_parts' => $allText,
            'all_parts' => $allPartsSection
        ];
        
        // Extract individual parts from ALL_PARTS section for more targeted searching
        // Each part might be a separate embedded email or MIME section
        if (!empty($allPartsSection)) {
            // Split by common MIME boundaries and headers
            $partDelimiters = [
                '/Content-Type:\s*message\/rfc822/is',
                '/Content-Type:\s*message\/rfc822-headers/is',
                '/^From:\s*/im',
                '/^Return-Path:\s*/im',
                '/^X-Original-/im',
            ];
            
            $parts = [$allPartsSection]; // Start with full section
            foreach ($partDelimiters as $delimiter) {
                $newParts = [];
                foreach ($parts as $part) {
                    $split = preg_split($delimiter, $part);
                    if (count($split) > 1) {
                        // Add delimiter back to each split part (except first)
                        foreach ($split as $idx => $splitPart) {
                            if ($idx > 0) {
                                // Find the delimiter text and prepend it
                                preg_match($delimiter, $part, $delimMatch, PREG_OFFSET_CAPTURE);
                                if ($delimMatch) {
                                    $splitPart = $delimMatch[0][0] . $splitPart;
                                }
                            }
                            if (!empty(trim($splitPart))) {
                                $newParts[] = $splitPart;
                            }
                        }
                    } else {
                        $newParts[] = $part;
                    }
                }
                $parts = $newParts;
            }
            
            // Add each individual part as a search target
            foreach ($parts as $idx => $part) {
                $searchTexts["all_parts_part_{$idx}"] = $part;
                $searchTexts["all_parts_part_{$idx}_lower"] = strtolower($part);
            }
        }
        
        // Method inspired by the example: split on "MIME-Version: 1.0" and search in fragments
        $fragments = explode("MIME-Version: 1.0", $this->rawEmail);
        if (count($fragments) > 1) {
            $searchTexts['mime_fragment'] = $fragments[1];
            $searchTexts['mime_fragment_lower'] = strtolower($fragments[1]);
        }
        
        // Also split on "MIME-Version: 1.0" in all parts section
        if (!empty($allPartsSection)) {
            $partsFragments = explode("MIME-Version: 1.0", $allPartsSection);
            if (count($partsFragments) > 1) {
                $searchTexts['parts_mime_fragment'] = $partsFragments[1];
                $searchTexts['parts_mime_fragment_lower'] = strtolower($partsFragments[1]);
            }
        }
        
        $originalTo = null;
        $ccAddresses = [];
        
        // Extract original To address
        $toPatterns = [
            '/Original-Recipient:\s*rfc822;\s*([^\s\r\n<>]+)/i',
            '/Final-Recipient:\s*rfc822;\s*([^\s\r\n<>]+)/i',
            '/X-Original-To:\s*([^\r\n<>]+)/i',
            '/^To:\s*([^\r\n<>]+)/im',
            '/\r\nTo:\s*([^\r\n<>]+)/i',
        ];
        
        foreach ($searchTexts as $sourceName => $text) {
            foreach ($toPatterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $candidate = trim($matches[1]);
                    // Extract email if it's in angle brackets
                    if (preg_match('/<([^>]+)>/', $candidate, $emailMatch)) {
                        $candidate = $emailMatch[1];
                    }
                    // Validate it's an email
                    if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                        $originalTo = $candidate;
                        break 2;
                    }
                }
            }
        }
        
        // Also check parsed headers
        if (!$originalTo) {
            $headers = $this->parsedData;
            if (isset($headers['x-original-to'])) {
                $to = is_array($headers['x-original-to']) ? $headers['x-original-to'][0] : $headers['x-original-to'];
                if (preg_match('/<([^>]+)>/', $to, $m)) {
                    $to = $m[1];
                }
                if (filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
                    $originalTo = trim($to);
                }
            }
        }
        
        $this->parsedData['original_to'] = $originalTo;
        
        // AGGRESSIVE CC EXTRACTION - Search for CC addresses in ALL possible formats and locations
        // This is critical for bounce emails where CC might be in embedded emails, attachments, or various formats
        $ccPatterns = [
            // Standard header patterns (case variations)
            '/^CC:\s*([^\r\n]+)/im',
            '/^Cc:\s*([^\r\n]+)/im',
            '/^cc:\s*([^\r\n]+)/im',
            '/\r\nCC:\s*([^\r\n]+)/i',
            '/\r\nCc:\s*([^\r\n]+)/i',
            '/\r\ncc:\s*([^\r\n]+)/i',
            '/\nCC:\s*([^\n]+)/i',
            '/\nCc:\s*([^\n]+)/i',
            '/\ncc:\s*([^\n]+)/i',
            
            // In original message sections (various delimiters)
            '/-----Original Message-----[\s\S]*?^CC:\s*([^\r\n]+)/im',
            '/-----Begin Original Message-----[\s\S]*?^CC:\s*([^\r\n]+)/im',
            '/-----Original Message-----[\s\S]*?^Cc:\s*([^\r\n]+)/im',
            '/^From:[\s\S]*?^CC:\s*([^\r\n]+)/im',
            '/^From:[\s\S]*?^Cc:\s*([^\r\n]+)/im',
            '/^To:[\s\S]*?^CC:\s*([^\r\n]+)/im',
            '/^To:[\s\S]*?^Cc:\s*([^\r\n]+)/im',
            
            // X- headers and variations
            '/^X-Original-CC:\s*([^\r\n]+)/im',
            '/^X-Original-Cc:\s*([^\r\n]+)/im',
            '/^Original-CC:\s*([^\r\n]+)/im',
            '/^Original-Cc:\s*([^\r\n]+)/im',
            '/^Resent-CC:\s*([^\r\n]+)/im',
            '/^Resent-Cc:\s*([^\r\n]+)/im',
            
            // EML attachment format - "Sent:" followed by "Cc:" (case insensitive, multiline)
            '/Sent:[^\r\n]*\r?\n[^\r\n]*\r?\nCc:\s*([^\r\n]+)/im',
            '/Sent:[^\r\n]*\r?\nCc:\s*([^\r\n]+)/im',
            '/Sent:[^\r\n]*\r?\n[^\r\n]*\r?\nCC:\s*([^\r\n]+)/im',
            '/Sent:[^\r\n]*\r?\nCC:\s*([^\r\n]+)/im',
            
            // After "To:" line, look for "Cc:" on next line (various spacing)
            '/To:[^\r\n]*\r?\n\s*Cc:\s*([^\r\n]+)/im',
            '/To:[^\r\n]*\r?\n\s*CC:\s*([^\r\n]+)/im',
            '/To:[^\r\n]*\n\s*Cc:\s*([^\n]+)/im',
            '/To:[^\r\n]*\n\s*CC:\s*([^\n]+)/im',
            
            // More flexible patterns - CC anywhere after To:
            '/To:[^\r\n]*(?:\r?\n[^\r\n]*){0,5}\r?\n\s*[Cc]{2}:\s*([^\r\n]+)/im',
            
            // Pattern for embedded emails: From/Sent/To/Cc sequence
            '/From:[^\r\n]*\r?\n(?:Sent:[^\r\n]*\r?\n)?To:[^\r\n]*\r?\n\s*[Cc]{2}:\s*([^\r\n]+)/im',
            
            // Very aggressive: Any line starting with CC: or Cc: (case variations)
            '/^[Cc]{2}:\s*([^\r\n]+)/im',
            '/\r\n[Cc]{2}:\s*([^\r\n]+)/i',
            '/\n[Cc]{2}:\s*([^\n]+)/i',
        ];
        
        foreach ($searchTexts as $sourceName => $text) {
            foreach ($ccPatterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $ccList = trim($match[1] ?? '');
                        if (!empty($ccList)) {
                            $parsed = $this->parseEmailList($ccList);
                            $ccAddresses = array_merge($ccAddresses, $parsed);
                        }
                    }
                }
            }
        }
        
        // Method from example: extract between "cc:" and "reply-to:" or "date:" or "subject:"
        foreach ($searchTexts as $sourceName => $text) {
            $lowerText = strtolower($text);
            
            // Try to find CC between "cc:" and "reply-to:"
            if (preg_match('/cc:\s*([^\r\n]*?)(?:\r?\n\s*(?:reply-to|date|subject|from|to):)/is', $lowerText, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Try between "cc:" and "date:"
            if (preg_match('/cc:\s*([^\r\n]*?)(?:\r?\n\s*date:)/is', $lowerText, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Try between "cc:" and "subject:"
            if (preg_match('/cc:\s*([^\r\n]*?)(?:\r?\n\s*subject:)/is', $lowerText, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Try to find CC after "to:" and before next header
            if (preg_match('/to:[^\r\n]*\r?\n\s*cc:\s*([^\r\n]+)/is', $lowerText, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Special pattern for EML format: "Sent: ... To: ... Cc: ... Subject:"
            // This matches the exact format from the example: "Sent: ... To: ... Cc: u18oneclick@airdriehockey.com <u18oneclick@airdriehockey.com>"
            if (preg_match('/sent:[^\r\n]*\r?\n[^\r\n]*to:[^\r\n]*\r?\n\s*cc:\s*([^\r\n]+)/is', $lowerText, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Also try case-sensitive search for "Cc:" (not just "cc:")
            if (preg_match('/Sent:[^\r\n]*\r?\n[^\r\n]*To:[^\r\n]*\r?\n\s*Cc:\s*([^\r\n]+)/is', $text, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // Pattern for EML attachment format with exact spacing
            // Matches: "From: ...\nSent: ...\nTo: ...\nCc: ...\nSubject: ..."
            if (preg_match('/From:[^\r\n]*\r?\nSent:[^\r\n]*\r?\nTo:[^\r\n]*\r?\nCc:\s*([^\r\n]+)/is', $text, $matches)) {
                $ccList = trim($matches[1]);
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // More aggressive: Look for any line that starts with "Cc:" or "CC:" anywhere in the text
            // This catches CC addresses even if they're not in the expected format
            if (preg_match_all('/^[Cc]{2}:\s*([^\r\n]+)/im', $text, $ccMatches, PREG_SET_ORDER)) {
                foreach ($ccMatches as $ccMatch) {
                    $ccList = trim($ccMatch[1]);
                    if (!empty($ccList)) {
                        $parsed = $this->parseEmailList($ccList);
                        $ccAddresses = array_merge($ccAddresses, $parsed);
                    }
                }
            }
            
            // Even more aggressive: Look for email addresses that appear after "To:" and before "Subject:" 
            // This catches CC addresses in various formats
            if (preg_match('/To:[^\r\n]*(?:\r?\n[^\r\n]*)*?\r?\n\s*([^\r\n]+)\r?\n\s*Subject:/is', $text, $matches)) {
                $potentialCc = trim($matches[1]);
                // Check if it looks like an email or contains emails
                if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $potentialCc)) {
                    $parsed = $this->parseEmailList($potentialCc);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
            
            // ULTRA AGGRESSIVE: Extract ALL email addresses that appear between "To:" and "Subject:" lines
            // This catches CC addresses even if the "Cc:" label is missing or malformed
            if (preg_match_all('/To:[^\r\n]*(?:\r?\n[^\r\n]*)*?\r?\n\s*([^\r\n]+)\r?\n\s*Subject:/is', $text, $potentialMatches, PREG_SET_ORDER)) {
                foreach ($potentialMatches as $potentialMatch) {
                    $potentialCc = trim($potentialMatch[1]);
                    // Extract all email addresses from this line
                    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $potentialCc, $emailMatches)) {
                        foreach ($emailMatches[0] as $email) {
                            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $ccAddresses[] = $email;
                            }
                        }
                    }
                }
            }
            
            // EXTREME: Search for email addresses near "To:" that aren't the "To:" address itself
            // This catches CC addresses that might be on the same line or nearby
            if (preg_match('/To:\s*([^\r\n]+)/i', $text, $toMatch)) {
                $toLine = $toMatch[0];
                // Get context around the To: line
                $toPos = strpos($text, $toLine);
                if ($toPos !== false) {
                    $context = substr($text, $toPos, 500); // 500 chars after To:
                    // Extract all emails from context
                    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $context, $contextEmails)) {
                        foreach ($contextEmails[0] as $email) {
                            // Skip if it's clearly the To: address (first email after To:)
                            if (stripos($toLine, $email) === false || strpos($context, $email) > 100) {
                                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $ccAddresses[] = $email;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Also check parsed headers
        $headerFields = ['x-original-cc', 'original-cc', 'cc', 'resent-cc', 'x-envelope-cc'];
        foreach ($headerFields as $field) {
            if (isset($this->parsedData[$field])) {
                $ccList = is_array($this->parsedData[$field]) 
                    ? $this->parsedData[$field][0] 
                    : $this->parsedData[$field];
                if (!empty($ccList)) {
                    $parsed = $this->parseEmailList($ccList);
                    $ccAddresses = array_merge($ccAddresses, $parsed);
                }
            }
        }
        
        // Normalize and deduplicate
        $ccAddresses = array_map('strtolower', array_map('trim', $ccAddresses));
        $ccAddresses = array_unique(array_filter($ccAddresses, function($email) {
            // First validate it's a proper email
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return false;
            }
            
            // Filter out Outlook UUID-based tracking addresses
            // Pattern: UUID (with or without hyphens) @ outlook.com server domains
            // Examples:
            // - 56778309-6384-4a0a-8411-8e41d0d02941@sa1p223mb1284.namp223.prod.outlook.com
            // - ff64fac0d1244afda1b7c7cd27836bb6@lv8p223mb1224.namp223.prod.outlook.com
            // - 93c7bb46-62b3-4e68-a3ec-d0185f617a36@pr3pr02mb6170.eurprd02.prod.outlook.com
            
            // Extract the local part (before @) and domain part
            $parts = explode('@', $email, 2);
            if (count($parts) !== 2) {
                return true; // Keep if we can't parse (shouldn't happen after validation)
            }
            
            $localPart = $parts[0];
            $domain = $parts[1];
            
            // Check if domain is a tracking/temporary email server domain
            // Pattern 1: Outlook server domains (prod.outlook.com, etc.)
            $isOutlookServerDomain = (
                preg_match('/\.(prod|namprd|eurprd|asprd)\.outlook\.com$/i', $domain) ||
                preg_match('/\.(namp|eurprd|asprd|namprd)\d+\.(prod|outlook)\.com$/i', $domain) ||
                preg_match('/^[a-z0-9]+p\d+mb\d+\.(namp|eurprd|asprd|namprd)\d+\.prod\.outlook\.com$/i', $domain)
            );
            
            // Pattern 2: Gmail tracking addresses (mail.gmail.com with long random strings)
            $isGmailTracking = (
                preg_match('/^mail\.gmail\.com$/i', $domain) &&
                // Gmail tracking addresses have long random strings with hyphens/underscores
                preg_match('/^[a-z0-9]+[+_-][a-z0-9]+[+_-][a-z0-9]+/i', $localPart) &&
                strlen($localPart) > 30 // Gmail tracking addresses are typically very long
            );
            
            // Pattern 3: Local/internal tracking domains (like remington.local)
            $isInternalTracking = (
                preg_match('/\.local$/i', $domain) &&
                // UUID pattern in local part
                (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $localPart) ||
                 preg_match('/^[0-9a-f]{32}$/i', $localPart))
            );
            
            if ($isOutlookServerDomain) {
                // Check if local part is a UUID (with or without hyphens)
                // UUID format: 8-4-4-4-12 hex characters (with hyphens)
                // Or: 32 hex characters (without hyphens)
                $isUuid = (
                    // UUID with hyphens: 8-4-4-4-12 pattern
                    preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $localPart) ||
                    // UUID without hyphens: 32 hex characters
                    preg_match('/^[0-9a-f]{32}$/i', $localPart)
                );
                
                if ($isUuid) {
                    // This is an Outlook tracking address - filter it out
                    return false;
                }
            }
            
            if ($isGmailTracking) {
                // This is a Gmail tracking address - filter it out
                return false;
            }
            
            if ($isInternalTracking) {
                // This is an internal tracking address - filter it out
                return false;
            }
            
            return true;
        }));
        $ccAddresses = array_values($ccAddresses);
        
        // Debug logging for CC extraction
        if (empty($ccAddresses)) {
            // Log a sample of the search texts to help debug
            $sampleText = '';
            $foundCc = false;
            foreach ($searchTexts as $name => $text) {
                if (stripos($text, 'cc:') !== false || stripos($text, 'Cc:') !== false) {
                    $foundCc = true;
                    $pos = stripos($text, 'cc:');
                    $sampleText = substr($text, max(0, $pos - 200), 600);
                    error_log("EmailParser: Found 'cc:' in source '{$name}' at position {$pos}. Sample: " . substr($sampleText, 0, 300));
                    break;
                }
            }
            if (!$foundCc) {
                // Check if we have any text that looks like email headers
                $hasEmailHeaders = false;
                foreach ($searchTexts as $name => $text) {
                    if (stripos($text, 'From:') !== false && stripos($text, 'To:') !== false) {
                        $hasEmailHeaders = true;
                        $sampleText = substr($text, 0, 1000);
                        error_log("EmailParser: No 'cc:' found but found email headers in '{$name}'. Sample: " . substr($sampleText, 0, 500));
                        break;
                    }
                }
                if (!$hasEmailHeaders) {
                    $sampleText = substr(implode("\n", array_slice($searchTexts, 0, 2)), 0, 500);
                    error_log("EmailParser: No CC addresses found. No 'cc:' or email headers found. Sample from first 2 sources: " . substr($sampleText, 0, 300));
                }
            }
        } else {
            error_log("EmailParser: SUCCESS - Found " . count($ccAddresses) . " CC addresses: " . implode(', ', $ccAddresses));
        }
        
        $this->parsedData['original_cc'] = $ccAddresses;
        
        // Extract original subject
        $subjectPatterns = [
            '/X-Original-Subject:\s*([^\r\n]+)/i',
            '/^Subject:\s*([^\r\n]+)/im',
        ];
        
        foreach ($searchTexts as $text) {
            foreach ($subjectPatterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $this->parsedData['original_subject'] = $this->decodeHeader(trim($matches[1]));
                    break 2;
                }
            }
        }
        
        if (!isset($this->parsedData['original_subject']) && isset($this->parsedData['subject'])) {
            $subject = is_array($this->parsedData['subject']) 
                ? $this->parsedData['subject'][0] 
                : $this->parsedData['subject'];
            $this->parsedData['original_subject'] = $subject;
        }
        
        // Extract original sent date
        $datePatterns = [
            '/X-Original-Date:\s*([^\r\n]+)/i',
            '/^Date:\s*([^\r\n]+)/im',
        ];
        
        foreach ($searchTexts as $text) {
            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    $this->parsedData['original_sent_date'] = $this->parseDate(trim($matches[1]));
                    break 2;
                }
            }
        }
        
        // Store decoded body for SMTP code extraction
        $this->parsedData['body'] = implode("\r\n\r\n", $this->allDecodedParts);
    }

    private function parseEmailList($emailList) {
        $emails = [];
        
        if (empty($emailList)) {
            return $emails;
        }
        
        // First, extract all email addresses using regex (handles all formats)
        // Format 1: "email@domain.com <email@domain.com>" (from EML attachments)
        // Format 2: "email@domain.com"
        // Format 3: "Name <email@domain.com>"
        // Format 4: "email@domain.com, email2@domain.com"
        // The regex captures email addresses both inside and outside angle brackets
        if (preg_match_all('/([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,})/i', $emailList, $emailMatches)) {
            foreach ($emailMatches[1] as $email) {
                $email = strtolower(trim($email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
        }
        
        // If regex didn't find anything, try splitting by common delimiters
        if (empty($emails)) {
            // Remove any angle brackets first (but keep the email inside)
            $emailList = preg_replace('/<([^>]+)>/', '$1', $emailList);
            
            // Split by common delimiters
            $parts = preg_split('/[,;]\s*/', $emailList);
            
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }
                
                // Extract email using regex
                if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/i', $part, $emailMatches)) {
                    foreach ($emailMatches[0] as $email) {
                        $email = strtolower(trim($email));
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $emails[] = $email;
                        }
                    }
                }
            }
        }
        
        return array_unique($emails);
    }

    private function extractSmtpCodes() {
        $allText = implode("\r\n\r\n", $this->allDecodedParts);
        
        $patterns = [
            '/(\d{3})\s+([^\r\n]+)/',  // Standard SMTP response
            '/Status:\s*(\d{3})/i',
            '/Diagnostic-Code:\s*[^\r\n]*?(\d{3})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $allText, $matches)) {
                $this->parsedData['smtp_code'] = $matches[1];
                if (isset($matches[2])) {
                    $this->parsedData['smtp_reason'] = trim($matches[2]);
                }
                break;
            }
        }

        if (preg_match('/Diagnostic-Code:\s*([^\r\n]+)/i', $allText, $matches)) {
            $this->parsedData['smtp_reason'] = trim($matches[1]);
        }
    }

    private function calculateSpamScore() {
        $score = 0;
        $headers = $this->parsedData;

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

        if (isset($headers['dkim-signature'])) {
            $score -= 5;
        }

        if (isset($headers['authentication-results'])) {
            $auth = is_array($headers['authentication-results']) 
                ? $headers['authentication-results'][0] 
                : $headers['authentication-results'];
            if (stripos($auth, 'pass') !== false) {
                $score -= 5;
            }
        }

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
        $headers = $this->parsedData;
        
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

        $autoReplyIndicators = [
            'out of office',
            'automatic reply',
            'auto reply',
            'vacation',
            'away from office',
        ];

        foreach ($autoReplyIndicators as $indicator) {
            if (stripos($subjectLower, $indicator) !== false) {
                return false;
            }
        }

        foreach ($bounceIndicators as $indicator) {
            if (stripos($subjectLower, $indicator) !== false) {
                return true;
            }
        }

        if (isset($this->parsedData['smtp_code'])) {
            $code = $this->parsedData['smtp_code'];
            $firstDigit = substr($code, 0, 1);
            if (in_array($firstDigit, ['4', '5'])) {
                return true;
            }
        }

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
