<?php

/**
 * Pure parsers for raw blacklist feed bodies. Generic — they accept any
 * line-oriented feed (one entry per line, "#"/";" comments, blank lines) and
 * normalize it into a clean list, so adding a new feed is a configuration
 * change, not code.
 */
class FeedParser
{
    /** Strip an inline "#"/";" comment and surrounding whitespace. */
    public static function cleanLine(string $line): string
    {
        $line = preg_replace('/[#;].*$/', '', $line) ?? $line;
        return trim($line);
    }

    /**
     * Parse an IP/CIDR feed into a de-duplicated list of network strings. Lines
     * that do not look like an IPv4/IPv6 address or CIDR are skipped.
     *
     * @return array<int,string>
     */
    public static function parseIp(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = self::cleanLine((string)$line);
            if ($line === '') {
                continue;
            }
            // Keep the network/host part only (drop any trailing tokens).
            $line = preg_split('/\s+/', $line)[0] ?? $line;
            if (self::looksLikeIpOrCidr($line)) {
                $out[$line] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Parse a user-agent feed into a de-duplicated list of lower-cased tokens.
     *
     * @return array<int,string>
     */
    public static function parseUa(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = self::cleanLine((string)$line);
            if ($line === '') {
                continue;
            }
            $out[strtolower($line)] = true;
        }
        return array_keys($out);
    }

    public static function looksLikeIpOrCidr(string $s): bool
    {
        if (strpos($s, ':') !== false) {
            // crude IPv6 / IPv6-CIDR check
            return (bool)preg_match('/^[0-9a-fA-F:]+(\/\d{1,3})?$/', $s);
        }
        return (bool)preg_match('/^\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?$/', $s);
    }
}
