<?php

require_once __DIR__ . '/Entity.php';

/**
 * Conversion-API integration. Generic and data-driven: a single HTTP request
 * template (method, URL, headers, body) with {token} placeholders covers any
 * external platform (FB CAPI / Google / TikTok / custom). Preset templates ship
 * as JSON data in templates/integrations/ and only fill these settings keys.
 *
 * Common settings keys:
 *   type        string  preset id (generic/fb_capi/google/tiktok), informational
 *   method      string  HTTP method (GET/POST), default POST
 *   url         string  endpoint URL, may contain {token} and {field:name}
 *   headers     array   header name => value template (tokens allowed)
 *   body        string  raw request body template (tokens allowed); JSON or form
 *   content_type string Content-Type header shortcut (default application/json)
 *   statuses    array   internal statuses that trigger this integration
 *                       (lead/sale/Purchase/...); empty = all
 *   enabled     bool    master on/off switch (default true)
 */
class Integration extends Entity
{
    public const TABLE = 'integrations';

    public function type(): string
    {
        return (string)$this->get('type', 'generic');
    }

    public function method(): string
    {
        $m = strtoupper((string)$this->get('method', 'POST'));
        return $m === 'GET' ? 'GET' : 'POST';
    }

    public function url(): string
    {
        return (string)$this->get('url', '');
    }

    /** @return array<string,string> header name => value template */
    public function headers(): array
    {
        $h = $this->get('headers', []);
        if (is_string($h)) {
            $decoded = json_decode($h, true);
            $h = is_array($decoded) ? $decoded : [];
        }
        return is_array($h) ? $h : [];
    }

    public function body(): string
    {
        return (string)$this->get('body', '');
    }

    public function contentType(): string
    {
        return (string)$this->get('content_type', 'application/json');
    }

    public function enabled(): bool
    {
        return (bool)$this->get('enabled', true);
    }

    /** @return array<int,string> internal statuses that trigger this integration */
    public function statuses(): array
    {
        $s = $this->get('statuses', []);
        if (is_string($s)) {
            $s = array_filter(array_map('trim', explode(',', $s)));
        }
        return is_array($s) ? array_values($s) : [];
    }

    /** Whether this integration should fire for the given internal status. */
    public function firesFor(string $status): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $statuses = $this->statuses();
        if ($statuses === []) {
            return true;
        }
        foreach ($statuses as $s) {
            if (strcasecmp((string)$s, $status) === 0) {
                return true;
            }
        }
        return false;
    }
}
