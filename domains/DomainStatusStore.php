<?php

/**
 * Server-local cache of per-domain health status (see {@see DomainStatusChecker}).
 *
 * Status is volatile, machine-specific runtime data — not configuration — so it
 * lives in a JSON file beside the database rather than in the domain's settings
 * bag (keeping config edits and the `updated_at` timestamp untouched by health
 * probes). Records are keyed by domain id. Writes are atomic and serialized
 * with a lock so the cron monitor and ad-hoc rechecks don't clobber each other.
 */
class DomainStatusStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (__DIR__ . '/../db/domain_status.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<int,array<string,mixed>> id => status record */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = (string)@file_get_contents($this->path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $rec) {
            if (is_array($rec)) {
                $out[(int)$id] = $rec;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $all = $this->all();
        return $all[$id] ?? null;
    }

    /**
     * Upsert one record. Re-reads under lock so concurrent writers merge
     * instead of overwriting the whole map.
     *
     * @param array<string,mixed> $record
     */
    public function put(int $id, array $record): void
    {
        $this->mutate(static function (array $all) use ($id, $record): array {
            $all[$id] = $record;
            return $all;
        });
    }

    /** Drop a domain's record (e.g. when the domain is deleted). */
    public function forget(int $id): void
    {
        $this->mutate(static function (array $all) use ($id): array {
            unset($all[$id]);
            return $all;
        });
    }

    /** Remove records for ids no longer present (housekeeping). @param list<int> $keepIds */
    public function prune(array $keepIds): void
    {
        $keep = array_fill_keys(array_map('intval', $keepIds), true);
        $this->mutate(static function (array $all) use ($keep): array {
            foreach (array_keys($all) as $id) {
                if (!isset($keep[(int)$id])) {
                    unset($all[$id]);
                }
            }
            return $all;
        });
    }

    /** @param callable(array<int,array<string,mixed>>):array<int,array<string,mixed>> $fn */
    private function mutate(callable $fn): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = @fopen($this->path, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $current = json_decode((string)$raw, true);
            $current = is_array($current) ? $current : [];
            $next = $fn($current);
            $encoded = (string)json_encode($next, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
