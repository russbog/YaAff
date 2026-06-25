<?php

/**
 * Generic, data-driven Cloudflare API client. Used to automate DNS records for
 * pool domains. The request builders are pure (no I/O); {@see send()} performs
 * the HTTP call with short timeouts and never throws — on any failure it returns
 * a deterministic result so the caller can report and continue.
 *
 * Credentials (API token, zone id) are always passed in by the caller (read
 * from a Domain's settings) — nothing is hardcoded and tokens are never logged.
 */
class CloudflareClient
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';
    public const CONNECT_TIMEOUT = 2;
    public const TOTAL_TIMEOUT = 3;

    /**
     * Build the request to create a DNS record in a zone. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildCreateDnsRecordRequest(string $token, string $zoneId, string $type, string $name, string $content, bool $proxied = false, int $ttl = 1): array
    {
        $payload = [
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];
        return [
            'method' => 'POST',
            'url' => self::API_BASE . '/zones/' . rawurlencode($zoneId) . '/dns_records',
            'headers' => self::authHeaders($token),
            'body' => (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Build the request to verify an API token. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildVerifyTokenRequest(string $token): array
    {
        return [
            'method' => 'GET',
            'url' => self::API_BASE . '/user/tokens/verify',
            'headers' => self::authHeaders($token),
            'body' => '',
        ];
    }

    /**
     * Build the request to look up a zone by name. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildListZonesRequest(string $token, string $name = ''): array
    {
        $url = self::API_BASE . '/zones';
        if ($name !== '') {
            $url .= '?name=' . rawurlencode($name);
        }
        return [
            'method' => 'GET',
            'url' => $url,
            'headers' => self::authHeaders($token),
            'body' => '',
        ];
    }

    /**
     * Build the request to list DNS records in a zone, optionally filtered by
     * name and type. Used to check whether a record already exists. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildListDnsRecordsRequest(string $token, string $zoneId, string $name = '', string $type = ''): array
    {
        $query = [];
        if ($name !== '') {
            $query['name'] = $name;
        }
        if ($type !== '') {
            $query['type'] = strtoupper($type);
        }
        $url = self::API_BASE . '/zones/' . rawurlencode($zoneId) . '/dns_records';
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return ['method' => 'GET', 'url' => $url, 'headers' => self::authHeaders($token), 'body' => ''];
    }

    /**
     * Build the request to read a zone's Universal SSL setting. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildGetUniversalSslRequest(string $token, string $zoneId): array
    {
        return [
            'method' => 'GET',
            'url' => self::API_BASE . '/zones/' . rawurlencode($zoneId) . '/ssl/universal/settings',
            'headers' => self::authHeaders($token),
            'body' => '',
        ];
    }

    /**
     * Build the request to enable/disable a zone's Universal SSL. Pure.
     *
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildSetUniversalSslRequest(string $token, string $zoneId, bool $enabled): array
    {
        return [
            'method' => 'PATCH',
            'url' => self::API_BASE . '/zones/' . rawurlencode($zoneId) . '/ssl/universal/settings',
            'headers' => self::authHeaders($token),
            'body' => (string)json_encode(['enabled' => $enabled], JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @return array<int,string> */
    private static function authHeaders(string $token): array
    {
        return [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
    }

    /** High-level: create a DNS record. @return array see {@see send()} */
    public static function createDnsRecord(string $token, string $zoneId, string $type, string $name, string $content, bool $proxied = false): array
    {
        if ($token === '' || $zoneId === '' || $name === '' || $content === '') {
            return self::failure('missing token, zone id, name or content');
        }
        return self::send(self::buildCreateDnsRecordRequest($token, $zoneId, $type, $name, $content, $proxied));
    }

    /** High-level: verify a token. @return array see {@see send()} */
    public static function verifyToken(string $token): array
    {
        if ($token === '') {
            return self::failure('missing token');
        }
        return self::send(self::buildVerifyTokenRequest($token));
    }

    /** High-level: list DNS records (optionally filtered). @return array see {@see send()} */
    public static function listDnsRecords(string $token, string $zoneId, string $name = '', string $type = ''): array
    {
        if ($token === '' || $zoneId === '') {
            return self::failure('missing token or zone id');
        }
        return self::send(self::buildListDnsRecordsRequest($token, $zoneId, $name, $type));
    }

    /** High-level: read the zone's Universal SSL setting. @return array see {@see send()} */
    public static function getUniversalSsl(string $token, string $zoneId): array
    {
        if ($token === '' || $zoneId === '') {
            return self::failure('missing token or zone id');
        }
        return self::send(self::buildGetUniversalSslRequest($token, $zoneId));
    }

    /** High-level: enable/disable the zone's Universal SSL. @return array see {@see send()} */
    public static function setUniversalSsl(string $token, string $zoneId, bool $enabled): array
    {
        if ($token === '' || $zoneId === '') {
            return self::failure('missing token or zone id');
        }
        return self::send(self::buildSetUniversalSslRequest($token, $zoneId, $enabled));
    }

    /**
     * Perform the request. Never throws. Parses Cloudflare's envelope
     * ({success, result, errors}) when present.
     *
     * @param array{method:string,url:string,headers:array<int,string>,body:string} $req
     * @return array{ok:bool,http_code:int,error:string,result:array<mixed>,raw:string}
     */
    public static function send(array $req): array
    {
        $curl = curl_init();
        $opts = [
            CURLOPT_URL => $req['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER => $req['headers'],
            CURLOPT_CUSTOMREQUEST => $req['method'],
        ];
        if ($req['method'] !== 'GET' && ($req['body'] ?? '') !== '') {
            $opts[CURLOPT_POSTFIELDS] = $req['body'];
        }
        curl_setopt_array($curl, $opts);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        curl_close($curl);

        $raw = $content === false ? '' : (string)$content;
        return self::parseResponse((int)($info['http_code'] ?? 0), (string)$error, $raw);
    }

    /**
     * Parse a Cloudflare HTTP response into the result envelope. Pure.
     *
     * @return array{ok:bool,http_code:int,error:string,result:array<mixed>,raw:string}
     */
    public static function parseResponse(int $httpCode, string $error, string $raw): array
    {
        if ($error !== '') {
            return ['ok' => false, 'http_code' => $httpCode, 'error' => $error, 'result' => [], 'raw' => $raw];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'http_code' => $httpCode, 'error' => 'invalid response', 'result' => [], 'raw' => $raw];
        }
        $success = ($decoded['success'] ?? false) === true;
        $errMsg = '';
        if (!$success && isset($decoded['errors']) && is_array($decoded['errors'])) {
            $parts = [];
            foreach ($decoded['errors'] as $e) {
                if (is_array($e) && isset($e['message'])) {
                    $parts[] = (string)$e['message'];
                }
            }
            $errMsg = implode('; ', $parts);
        }
        return [
            'ok' => $success,
            'http_code' => $httpCode,
            'error' => $errMsg,
            'result' => is_array($decoded['result'] ?? null) ? $decoded['result'] : [],
            'raw' => $raw,
        ];
    }

    /** @return array{ok:bool,http_code:int,error:string,result:array<mixed>,raw:string} */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'http_code' => 0, 'error' => $error, 'result' => [], 'raw' => ''];
    }
}
