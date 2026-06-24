<?php

require_once __DIR__ . '/../bases/iputils.php';

/**
 * Offline store of auto-updated IP/UA blacklists. Matching is fully local (no
 * network calls in the request path), so the tracker can flag datacenter /
 * proxy / VPN IPs and known bot user-agents fast and deterministically.
 *
 * Each feed is cached as a file in the store directory, named "<feed>.ip" or
 * "<feed>.ua". Files are plain line lists (CIDRs for ip, lower-case tokens for
 * ua). The store lazily loads and aggregates all cache files of each kind.
 */
class BlacklistStore
{
    private string $dir;
    /** @var array<int,string>|null */
    private ?array $ipNets = null;
    /** @var array<int,string>|null */
    private ?array $uaTokens = null;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (__DIR__ . '/../bases/blacklists'), '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Cache file path for a feed of the given type ("ip"|"ua"). */
    public function cachePath(string $feed, string $type): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $feed) ?? $feed;
        return $this->dir . '/' . $safe . '.' . $type;
    }

    /**
     * Write a feed cache atomically. Returns the number of entries written.
     *
     * @param array<int,string> $lines
     */
    public function writeFeed(string $feed, string $type, array $lines): int
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $path = $this->cachePath($feed, $type);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        rename($tmp, $path);
        $this->ipNets = null;
        $this->uaTokens = null;
        return count($lines);
    }

    /** Is $ip listed in any IP blacklist feed? */
    public function matchesIp(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $nets = $this->ipNets();
        return $nets !== [] && IpUtils::checkIp($ip, $nets);
    }

    /** Does $ua contain any blacklisted user-agent token? */
    public function matchesUa(string $ua): bool
    {
        if ($ua === '') {
            return false;
        }
        $ua = strtolower($ua);
        foreach ($this->uaTokens() as $token) {
            if ($token !== '' && strpos($ua, $token) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    private function ipNets(): array
    {
        if ($this->ipNets === null) {
            $this->ipNets = $this->loadType('ip');
        }
        return $this->ipNets;
    }

    /** @return array<int,string> */
    private function uaTokens(): array
    {
        if ($this->uaTokens === null) {
            $this->uaTokens = $this->loadType('ua');
        }
        return $this->uaTokens;
    }

    /** @return array<int,string> */
    private function loadType(string $type): array
    {
        $all = [];
        foreach (glob($this->dir . '/*.' . $type) ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $l) {
                    $l = trim($l);
                    if ($l !== '') {
                        $all[$l] = true;
                    }
                }
            }
        }
        return array_keys($all);
    }
}
