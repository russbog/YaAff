<?php

require_once __DIR__ . '/../entities/Integration.php';
require_once __DIR__ . '/../tokens/TokenRegistry.php';

/**
 * Generic Conversion-API sender. Builds an HTTP request from an
 * {@see Integration} template by substituting {token} placeholders, then sends
 * it with strict timeouts. External failures never throw — a deterministic
 * fallback result (http_code 0) is returned so the postback flow keeps working.
 *
 * Template rendering is pure and unit-testable; only {@see send()} performs I/O.
 */
class ConversionApiSender
{
    public const CONNECT_TIMEOUT = 2;
    public const TOTAL_TIMEOUT = 3;

    /**
     * Build a flat {token} map from a click row plus conversion fields. Generic
     * and pure: exposes click columns directly ({ip}, {country}, {ua}, ...),
     * custom click params as {c.NAME}, and conversion fields ({clickid},
     * {status}, {payout}, {revenue}, {currency}, {time}). Extra request params
     * (e.g. {tid}) are merged last and win over click columns.
     *
     * @param array<string,mixed> $click   click row (params may be array or JSON)
     * @param array<string,mixed> $request raw conversion request params
     * @return array<string,scalar|null>
     */
    public static function buildTokens(array $click, string $status, float $payout, float $revenue, string $currency, array $request = []): array
    {
        $overrides = [
            'status' => $status,
            'payout' => $payout,
            'revenue' => $revenue,
            'currency' => $currency,
            'time' => time(),
        ];
        foreach ($request as $rk => $rv) {
            if (is_scalar($rv) || $rv === null) {
                $overrides[(string)$rk] = $rv;
            }
        }
        return TokenRegistry::fromClick($click, $overrides)->toArray();
    }

    /**
     * Replace {token} placeholders in a template string with values from the
     * token map. Unknown tokens are left untouched. Values are not URL-encoded
     * here; callers encode per-context where needed.
     *
     * @param array<string,scalar|null> $tokens
     */
    public static function renderTemplate(string $template, array $tokens): string
    {
        if ($template === '' || strpos($template, '{') === false) {
            return $template;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_.:-]+)\}/', static function (array $m) use ($tokens): string {
            $key = $m[1];
            if (array_key_exists($key, $tokens)) {
                return (string)$tokens[$key];
            }
            return $m[0];
        }, $template);
    }

    /**
     * Build the concrete request (method, url, headers, body) for an
     * integration given a token map. Pure — no I/O.
     *
     * @param array<string,scalar|null> $tokens
     * @return array{method:string,url:string,headers:array<int,string>,body:string}
     */
    public static function buildRequest(Integration $integration, array $tokens): array
    {
        $method = $integration->method();
        $url = self::renderTemplate($integration->url(), $tokens);
        $body = self::renderTemplate($integration->body(), $tokens);

        $headers = [];
        $hasContentType = false;
        foreach ($integration->headers() as $name => $value) {
            $rendered = self::renderTemplate((string)$value, $tokens);
            $headers[] = $name . ': ' . $rendered;
            if (strcasecmp((string)$name, 'Content-Type') === 0) {
                $hasContentType = true;
            }
        }
        if ($method === 'POST' && !$hasContentType && $body !== '') {
            $headers[] = 'Content-Type: ' . $integration->contentType();
        }

        return ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Send the integration request. Never throws; returns a result array even on
     * total failure so callers can log and continue.
     *
     * @param array<string,scalar|null> $tokens
     * @return array{http_code:int,error:string,content:string,url:string}
     */
    public static function send(Integration $integration, array $tokens): array
    {
        $req = self::buildRequest($integration, $tokens);
        if ($req['url'] === '') {
            return ['http_code' => 0, 'error' => 'empty url', 'content' => '', 'url' => ''];
        }

        $curl = curl_init();
        $opts = [
            CURLOPT_URL => $req['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_HTTPHEADER => $req['headers'],
        ];
        if ($req['method'] === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $req['body'];
        }
        curl_setopt_array($curl, $opts);
        $content = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        curl_close($curl);

        return [
            'http_code' => (int)($info['http_code'] ?? 0),
            'error' => (string)$error,
            'content' => $content === false ? '' : (string)$content,
            'url' => $req['url'],
        ];
    }
}
