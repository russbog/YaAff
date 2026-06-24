<?php

/**
 * Unified token registry — the single source of truth for resolving {token}
 * placeholders across the tracker (landing pages, URL macros, S2S postbacks,
 * Conversion API). Every layer resolves the same token names the same way, so
 * token logic is no longer duplicated per consumer.
 *
 * A registry is built around one click context: the click id / user id, the
 * click row (columns + custom params) and an optional set of explicit
 * overrides (e.g. conversion status/payout, request params) which always win.
 * Click data can be supplied directly (Conversion API already has the row) or
 * loaded lazily on first miss via a loader callback (URL/HTML macros).
 *
 * Resolution is centralized in {@see resolve()}; {@see render()} performs inline
 * substitution leaving unknown tokens intact; {@see toArray()} exposes a flat
 * map for the template-based Conversion API.
 */
class TokenRegistry
{
    /** Click columns exposed as bare tokens, e.g. {country}, {os}, {ua}. */
    public const CLICK_COLUMNS = [
        'ip', 'country', 'region', 'city', 'lang', 'os', 'osver', 'client',
        'clientver', 'device', 'brand', 'model', 'isp', 'connection_type',
        'ua', 'status',
    ];

    /** Click row keys that are not scalar tokens. */
    private const NON_TOKEN_KEYS = ['params', 'path', 'events', 'leaddata'];

    private ?string $clickid;
    private ?string $userid;
    /** @var array<string,mixed> known click row (columns and optionally 'params') */
    private array $click;
    /** @var array<string,scalar|null> explicit overrides; win over click data */
    private array $overrides;
    /** @var null|callable():(?array<string,mixed>) lazy click-row loader */
    private $loader;
    private bool $loaderRun = false;
    /** @var array<string,mixed>|null memoized decoded custom params */
    private ?array $paramsCache = null;

    /**
     * @param array<string,mixed>      $click     known click row
     * @param array<string,scalar|null> $overrides explicit tokens (win over click)
     * @param null|callable():(?array<string,mixed>) $loader lazy click loader
     */
    public function __construct(?string $clickid = null, ?string $userid = null, array $click = [], array $overrides = [], ?callable $loader = null)
    {
        $this->clickid = $clickid;
        $this->userid = $userid;
        $this->click = $click;
        $this->overrides = $overrides;
        $this->loader = $loader;
    }

    /** Build a registry from a full click row (Conversion API path). */
    public static function fromClick(array $click, array $overrides = []): self
    {
        $clickid = isset($click['clickid']) ? (string)$click['clickid'] : null;
        $userid = isset($click['userid']) ? (string)$click['userid'] : null;
        return new self($clickid, $userid, $click, $overrides);
    }

    /** Return a copy with extra overrides merged in (new values win). */
    public function withOverrides(array $overrides): self
    {
        $clone = clone $this;
        $clone->overrides = $overrides + $this->overrides;
        return $clone;
    }

    /**
     * Resolve a single token to its string value, or null when the token is
     * unknown / has no value. Overrides win, then well-known dynamic tokens,
     * then custom params ({c.NAME}, {subN}), then click columns.
     */
    public function resolve(string $token): ?string
    {
        if (array_key_exists($token, $this->overrides)) {
            $v = $this->overrides[$token];
            return $v === null ? null : (string)$v;
        }

        return match (true) {
            $token === 'clickid' => $this->clickid,
            $token === 'userid'  => $this->userid,
            $token === 'domain'  => $_SERVER['HTTP_HOST'] ?? null,
            $token === 'time'    => (string)time(),
            $token === 'px'      => function_exists('get_cookie') ? (string)get_cookie('px') : null,
            str_starts_with($token, 'c.')      => $this->customParam(substr($token, 2)),
            str_starts_with($token, 'hash:')   => $this->hash(substr($token, 5)),
            str_starts_with($token, 'random:') => $this->random(substr($token, 7)),
            self::isSubToken($token)           => $this->customParam($token),
            in_array($token, self::CLICK_COLUMNS, true) => $this->column($token),
            default => null,
        };
    }

    /**
     * Replace every {token} in the template with its resolved value. Unknown
     * tokens are left untouched. Values are not URL-encoded here; callers encode
     * per-context where needed.
     */
    public function render(string $template): string
    {
        if ($template === '' || strpos($template, '{') === false) {
            return $template;
        }
        return preg_replace_callback('/\{([a-zA-Z0-9_.:-]+)\}/', function (array $m): string {
            $v = $this->resolve($m[1]);
            return $v === null ? $m[0] : $v;
        }, $template);
    }

    /**
     * Flat map of concrete tokens for the template Conversion API: click
     * columns by name, custom params as {c.NAME}, then overrides (which win).
     *
     * @return array<string,scalar|null>
     */
    public function toArray(): array
    {
        $tokens = [];
        foreach ($this->click as $k => $v) {
            if (in_array($k, self::NON_TOKEN_KEYS, true)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $tokens[(string)$k] = $v;
            }
        }
        foreach ($this->params() as $pk => $pv) {
            if (is_scalar($pv) || $pv === null) {
                $tokens['c.' . $pk] = $pv;
            }
        }
        foreach ($this->overrides as $ok => $ov) {
            if (is_scalar($ov) || $ov === null) {
                $tokens[(string)$ok] = $ov;
            }
        }
        return $tokens;
    }

    private static function isSubToken(string $token): bool
    {
        return (bool)preg_match('/^sub_?(id_?)?\d+$/', $token);
    }

    private function column(string $name): ?string
    {
        if (isset($this->click[$name]) && is_scalar($this->click[$name])) {
            return (string)$this->click[$name];
        }
        $this->ensureClickData();
        return isset($this->click[$name]) && is_scalar($this->click[$name])
            ? (string)$this->click[$name]
            : null;
    }

    private function customParam(string $name): ?string
    {
        $params = $this->params();
        if (array_key_exists($name, $params) && is_scalar($params[$name])) {
            return (string)$params[$name];
        }
        if (isset($this->click[$name]) && is_scalar($this->click[$name])) {
            return (string)$this->click[$name];
        }
        $this->ensureClickData();
        $params = $this->params();
        return array_key_exists($name, $params) && is_scalar($params[$name])
            ? (string)$params[$name]
            : null;
    }

    private function hash(string $inner): ?string
    {
        $value = $this->resolve($inner);
        return $value === null ? null : md5($value);
    }

    private function random(string $range): ?string
    {
        $parts = explode('-', $range);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }
        return (string)random_int((int)$parts[0], (int)$parts[1]);
    }

    /** @return array<string,mixed> */
    private function params(): array
    {
        if ($this->paramsCache !== null) {
            return $this->paramsCache;
        }
        $raw = $this->click['params'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $this->paramsCache = is_array($raw) ? $raw : [];
        return $this->paramsCache;
    }

    private function ensureClickData(): void
    {
        if ($this->loaderRun || $this->loader === null) {
            return;
        }
        $this->loaderRun = true;
        $row = ($this->loader)();
        if (is_array($row)) {
            $this->click += $row;
            $this->paramsCache = null;
        }
    }
}
