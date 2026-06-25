<?php

/**
 * Best-effort detection of this server's own public IPv4 — the address that a
 * domain's A record must point at for the domain to be served here. Works in
 * both web (SERVER_ADDR) and CLI (cron monitor) contexts, and can be overridden
 * explicitly via the YAAFF_SERVER_IP environment variable.
 */
class ServerIp
{
    public static function detect(): string
    {
        $env = (string)getenv('YAAFF_SERVER_IP');
        if (self::isPublicIpv4($env)) {
            return $env;
        }

        $addr = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if (self::isPublicIpv4($addr)) {
            return $addr;
        }

        // CLI / behind a proxy: scan the host's configured addresses.
        if (function_exists('exec')) {
            $out = [];
            @exec('hostname -I 2>/dev/null', $out);
            foreach (preg_split('/\s+/', trim(implode(' ', $out))) as $candidate) {
                if (self::isPublicIpv4($candidate)) {
                    return $candidate;
                }
            }
        }

        $resolved = (string)@gethostbyname((string)gethostname());
        return filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $resolved : ($addr ?: '127.0.0.1');
    }

    public static function isPublicIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
