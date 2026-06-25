<?php

/**
 * Computes the live health status of a pool {@see Domain}: whether its DNS
 * points at this server (or Cloudflare) and whether HTTPS serves a valid,
 * non-expiring certificate. The {@see classify()} method is pure (no I/O) so
 * the whole state machine is unit-testable; {@see check()} performs the actual
 * DNS resolution and TLS handshake and feeds the results into it.
 *
 * The vocabulary is deliberately small and user-facing:
 *   ok          DNS resolves here and HTTPS serves a valid certificate
 *   dns_await   no A record yet / not pointing here (still propagating)
 *   dns_error   resolves to a different IP than this server (misconfigured)
 *   ssl_await   DNS is fine but HTTPS isn't answering with a cert yet
 *   ssl_error   a certificate is served but it's expired / wrong host / invalid
 *   unreachable could not probe the host at all
 *   na          checks don't apply (wildcard / alias domains)
 */
class DomainStatusChecker
{
    public const OK          = 'ok';
    public const DNS_AWAIT   = 'dns_await';
    public const DNS_ERROR   = 'dns_error';
    public const SSL_AWAIT   = 'ssl_await';
    public const SSL_ERROR   = 'ssl_error';
    public const UNREACHABLE = 'unreachable';
    public const NA          = 'na';

    /** Renew/refresh once the certificate has this many days or fewer left. */
    public const RENEW_THRESHOLD_DAYS = 14;

    public const DNS_TIMEOUT = 3;
    public const SSL_TIMEOUT = 4;

    /**
     * Pure state machine: turn raw DNS + TLS probe results into a status.
     *
     * @param array{applicable:bool,resolves:bool,ip:?string,cloudflare:bool,error:?string} $dns
     * @param array{reachable:bool,valid:bool,expires_at:?int,host_match:bool,error:?string} $ssl
     * @return array{status:string,detail:string,ip:?string,cloudflare:bool,ssl_expires_at:?int,days_left:?int,needs_fix:bool}
     */
    public static function classify(array $dns, array $ssl, int $now): array
    {
        $base = [
            'status' => self::UNREACHABLE,
            'detail' => '',
            'ip' => $dns['ip'] ?? null,
            'cloudflare' => (bool)($dns['cloudflare'] ?? false),
            'ssl_expires_at' => $ssl['expires_at'] ?? null,
            'days_left' => null,
            'needs_fix' => false,
        ];

        // Wildcard / alias domains can't be probed directly.
        if (($dns['applicable'] ?? true) === false) {
            return ['status' => self::NA, 'detail' => 'Checks not applicable for this domain type'] + $base;
        }

        // DNS layer first — without DNS there's nothing to probe over HTTPS.
        if (($dns['resolves'] ?? false) !== true) {
            if (($dns['ip'] ?? null) === null) {
                return ['status' => self::DNS_AWAIT, 'detail' => 'Waiting for DNS — no A record found yet'] + $base;
            }
            return [
                'status' => self::DNS_ERROR,
                'detail' => (string)($dns['error'] ?? 'Domain resolves to an unexpected IP'),
            ] + $base;
        }

        // DNS is good. Inspect the certificate served over HTTPS.
        if (($ssl['reachable'] ?? false) !== true) {
            return [
                'status' => self::SSL_AWAIT,
                'detail' => 'DNS is correct — waiting for HTTPS/SSL to come up',
                'needs_fix' => true,
            ] + $base;
        }

        if (($ssl['valid'] ?? false) !== true) {
            $detail = (string)($ssl['error'] ?? '');
            if ($detail === '') {
                $detail = ($ssl['host_match'] ?? true) === false
                    ? 'Certificate does not cover this hostname'
                    : 'Invalid certificate';
            }
            return ['status' => self::SSL_ERROR, 'detail' => $detail, 'needs_fix' => true] + $base;
        }

        // Valid certificate — surface days left and flag near-expiry for renewal.
        $daysLeft = null;
        $expiresAt = $ssl['expires_at'] ?? null;
        if (is_int($expiresAt)) {
            $daysLeft = (int)floor(($expiresAt - $now) / 86400);
        }
        $needsFix = $daysLeft !== null && $daysLeft <= self::RENEW_THRESHOLD_DAYS;
        $detail = $daysLeft === null
            ? 'HTTPS is serving a valid certificate'
            : ($needsFix
                ? "Certificate expires in {$daysLeft} day(s) — will be renewed"
                : "Certificate valid, {$daysLeft} day(s) left");

        return [
            'status' => self::OK,
            'detail' => $detail,
            'ip' => $dns['ip'] ?? null,
            'cloudflare' => (bool)($dns['cloudflare'] ?? false),
            'ssl_expires_at' => is_int($expiresAt) ? $expiresAt : null,
            'days_left' => $daysLeft,
            'needs_fix' => $needsFix,
        ];
    }

    /**
     * Full live check for a domain. Performs DNS resolution and a TLS probe,
     * then classifies. Never throws.
     *
     * @return array{status:string,detail:string,ip:?string,cloudflare:bool,ssl_expires_at:?int,days_left:?int,needs_fix:bool,host:string,checked_at:int}
     */
    public function check(Domain $domain, string $serverIp): array
    {
        $now = time();
        $host = $domain->host();

        if ($domain->type() !== Domain::TYPE_REGULAR) {
            $result = self::classify(['applicable' => false, 'resolves' => false, 'ip' => null, 'cloudflare' => false, 'error' => null], self::emptySsl(), $now);
            return $result + ['host' => $host, 'checked_at' => $now];
        }

        $dns = $this->resolveDns($host, $serverIp);
        $ssl = $dns['resolves'] ? $this->probeSsl($host) : self::emptySsl();
        $result = self::classify($dns, $ssl, $now);
        return $result + ['host' => $host, 'checked_at' => $now];
    }

    /**
     * Resolve the host's A record and decide whether it points at this server
     * (directly or via a Cloudflare proxy). Mirrors admin/domaincheck.php.
     *
     * @return array{applicable:bool,resolves:bool,ip:?string,cloudflare:bool,error:?string}
     */
    public function resolveDns(string $host, string $serverIp): array
    {
        $host = preg_replace('#^https?://#i', '', $host);
        $host = rtrim((string)$host, '/');
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        $records = @dns_get_record($host, DNS_A);
        if ($records === false || empty($records)) {
            return ['applicable' => true, 'resolves' => false, 'ip' => null, 'cloudflare' => false, 'error' => 'No A record found yet'];
        }

        $ip = (string)$records[0]['ip'];
        $cloudflare = $this->isCloudflareIp($ip);
        $resolves = ($ip === $serverIp) || $cloudflare;

        return [
            'applicable' => true,
            'resolves' => $resolves,
            'ip' => $ip,
            'cloudflare' => $cloudflare,
            'error' => $resolves ? null : "Resolves to {$ip}, but this server is {$serverIp}",
        ];
    }

    /** Whether an IP belongs to Cloudflare (best-effort via the ASN database). */
    public function isCloudflareIp(string $ip): bool
    {
        if (!function_exists('getisp')) {
            return false;
        }
        try {
            $isp = getisp($ip);
            return is_string($isp) && str_contains(strtolower($isp), 'cloudflare');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Open a TLS connection to https://host and inspect the served certificate.
     * Distinguishes "no cert yet" (await) from "bad cert" (error) by probing
     * once with verification on, then once with it off to read the reason.
     *
     * @return array{reachable:bool,valid:bool,expires_at:?int,host_match:bool,error:?string}
     */
    public function probeSsl(string $host): array
    {
        // Strict probe: would a browser accept this certificate as-is?
        [$strictOk] = $this->tlsConnect($host, true);
        // Lenient probe: capture the cert even if invalid, to explain why.
        [$lenientOk, $cert] = $this->tlsConnect($host, false);

        if (!$lenientOk && !$strictOk) {
            return ['reachable' => false, 'valid' => false, 'expires_at' => null, 'host_match' => false, 'error' => null];
        }

        $expiresAt = null;
        $hostMatch = true;
        $error = null;
        if (is_array($cert)) {
            $parsed = @openssl_x509_parse($cert['__pem__'] ?? '');
            if (is_array($parsed)) {
                $expiresAt = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : null;
                $hostMatch = self::certCoversHost($parsed, $host);
                if ($expiresAt !== null && $expiresAt < time()) {
                    $error = 'Certificate has expired';
                } elseif (!$hostMatch) {
                    $error = 'Certificate does not cover this hostname';
                }
            }
        }

        if ($strictOk && $error === null) {
            return ['reachable' => true, 'valid' => true, 'expires_at' => $expiresAt, 'host_match' => $hostMatch, 'error' => null];
        }

        return [
            'reachable' => true,
            'valid' => false,
            'expires_at' => $expiresAt,
            'host_match' => $hostMatch,
            'error' => $error ?? 'Certificate is not trusted',
        ];
    }

    /**
     * Attempt a TLS handshake. With $verify the peer + hostname are validated
     * (so success means a browser-valid chain). Returns [success, certInfo]
     * where certInfo carries the captured PEM under '__pem__' when available.
     *
     * @return array{0:bool,1:?array<string,mixed>}
     */
    private function tlsConnect(string $host, bool $verify): array
    {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $client = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $errstr,
            self::SSL_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($client === false) {
            return [false, null];
        }
        $params = stream_context_get_params($client);
        fclose($client);

        $certInfo = null;
        $resource = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($resource !== null && @openssl_x509_export($resource, $pem)) {
            $certInfo = ['__pem__' => $pem];
        }
        return [true, $certInfo];
    }

    /** Whether a parsed X.509 certificate covers $host (CN or SAN, incl. wildcards). */
    public static function certCoversHost(array $parsed, string $host): bool
    {
        $host = strtolower($host);
        $names = [];
        $cn = $parsed['subject']['CN'] ?? null;
        if (is_string($cn)) {
            $names[] = strtolower($cn);
        }
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (stripos($entry, 'DNS:') === 0) {
                    $names[] = strtolower(substr($entry, 4));
                }
            }
        }
        foreach ($names as $name) {
            if ($name === $host) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // ".example.com"
                $hostParent = strstr($host, '.'); // ".example.com" for "a.example.com"
                if ($hostParent !== false && strtolower($hostParent) === $suffix) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array{reachable:bool,valid:bool,expires_at:?int,host_match:bool,error:?string} */
    private static function emptySsl(): array
    {
        return ['reachable' => false, 'valid' => false, 'expires_at' => null, 'host_match' => false, 'error' => null];
    }
}
