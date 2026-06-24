<?php

/**
 * Pure domain matching and alias resolution. Centralizes the wildcard-matching
 * logic (previously inline in Db::match_domain) so it is shared and unit
 * testable, and adds domain-pool alias resolution.
 */
class DomainMatcher
{
    /** Normalize a host: trim, lower-case, strip a trailing dot. */
    public static function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    /**
     * Does $host match any entry in $domains? An entry may be an exact host or a
     * wildcard pattern containing "*" (e.g. "*.example.com").
     *
     * @param array<int,string> $domains
     */
    public static function matches(array $domains, string $host): bool
    {
        $host = self::normalize($host);
        foreach ($domains as $domain) {
            $domain = self::normalize((string)$domain);
            if ($domain === '') {
                continue;
            }
            if ($domain === $host) {
                return true;
            }
            if (strpos($domain, '*') !== false && self::matchesWildcard($domain, $host)) {
                return true;
            }
        }
        return false;
    }

    /** Match a single wildcard pattern (with "*") against a host. */
    public static function matchesWildcard(string $pattern, string $host): bool
    {
        $pattern = self::normalize($pattern);
        $host = self::normalize($host);
        $regex = str_replace(['.', '*'], ['\.', '.*'], $pattern);
        return (bool)preg_match('/^' . $regex . '$/', $host);
    }

    /**
     * Build a flat alias map (alias host => canonical host) from domain-pool
     * rows. Each row is [name, settings(JSON|array)]. Only rows whose settings
     * type is "alias" and that carry a non-empty alias_of are included.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string>
     */
    public static function buildAliasMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $settings = $row['settings'] ?? [];
            if (is_string($settings)) {
                $decoded = json_decode($settings, true);
                $settings = is_array($decoded) ? $decoded : [];
            }
            if (($settings['type'] ?? '') !== 'alias') {
                continue;
            }
            $aliasOf = self::normalize((string)($settings['alias_of'] ?? ''));
            $host = self::normalize((string)($row['name'] ?? ''));
            if ($host === '' || $aliasOf === '') {
                continue;
            }
            $map[$host] = $aliasOf;
        }
        return $map;
    }

    /**
     * Resolve a host through an alias map (following at most a few hops to avoid
     * cycles). Returns the canonical host, or the input host when it is not an
     * alias.
     *
     * @param array<string,string> $aliasMap
     */
    public static function resolveAlias(array $aliasMap, string $host): string
    {
        $host = self::normalize($host);
        $seen = [];
        while (isset($aliasMap[$host]) && !isset($seen[$host])) {
            $seen[$host] = true;
            $host = $aliasMap[$host];
        }
        return $host;
    }
}
