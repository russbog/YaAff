<?php

require_once __DIR__ . '/FeedParser.php';
require_once __DIR__ . '/BlacklistStore.php';

/**
 * Downloads configurable IP/UA blacklist feeds and refreshes the offline
 * {@see BlacklistStore} cache. Generic and data-driven: feeds are described by
 * data (name/type/url/enabled), not code. The HTTP fetcher is injectable so the
 * update logic is unit-testable without network access.
 *
 * Failure of any feed is non-fatal: the previous cache file is left untouched,
 * so a feed outage never empties the blacklists.
 */
class BlacklistUpdater
{
    /** Default fetch timeouts for the (out-of-request-path) cron updater. */
    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 60;

    private BlacklistStore $store;
    /** @var callable(string):array{ok:bool,body:string,error:string} */
    private $fetcher;

    /**
     * @param callable(string):array{ok:bool,body:string,error:string}|null $fetcher
     */
    public function __construct(BlacklistStore $store, ?callable $fetcher = null)
    {
        $this->store = $store;
        $this->fetcher = $fetcher ?? [self::class, 'httpFetch'];
    }

    /**
     * Update every enabled feed. Returns a per-feed result list.
     *
     * @param array<int,array<string,mixed>> $feeds
     * @return array<int,array{name:string,type:string,ok:bool,count:int,error:string,skipped:bool}>
     */
    public function update(array $feeds): array
    {
        $results = [];
        foreach ($feeds as $feed) {
            $name = (string)($feed['name'] ?? '');
            $type = (string)($feed['type'] ?? '');
            $url = (string)($feed['url'] ?? '');
            $enabled = ($feed['enabled'] ?? true) !== false;

            if (!$enabled) {
                $results[] = $this->result($name, $type, true, 0, '', true);
                continue;
            }
            if ($name === '' || $url === '' || !in_array($type, ['ip', 'ua'], true)) {
                $results[] = $this->result($name, $type, false, 0, 'invalid feed config', false);
                continue;
            }

            $fetched = ($this->fetcher)($url);
            if (!($fetched['ok'] ?? false) || ($fetched['body'] ?? '') === '') {
                // keep existing cache; record the failure
                $results[] = $this->result($name, $type, false, 0, (string)($fetched['error'] ?? 'fetch failed'), false);
                continue;
            }

            $lines = $type === 'ip'
                ? FeedParser::parseIp((string)$fetched['body'])
                : FeedParser::parseUa((string)$fetched['body']);
            if ($lines === []) {
                $results[] = $this->result($name, $type, false, 0, 'no entries parsed', false);
                continue;
            }

            $count = $this->store->writeFeed($name, $type, $lines);
            $results[] = $this->result($name, $type, true, $count, '', false);
        }
        return $results;
    }

    /**
     * @return array{name:string,type:string,ok:bool,count:int,error:string,skipped:bool}
     */
    private function result(string $name, string $type, bool $ok, int $count, string $error, bool $skipped): array
    {
        return ['name' => $name, 'type' => $type, 'ok' => $ok, 'count' => $count, 'error' => $error, 'skipped' => $skipped];
    }

    /**
     * Default HTTP fetcher. Never throws.
     *
     * @return array{ok:bool,body:string,error:string}
     */
    public static function httpFetch(string $url): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
        ]);
        $body = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        if ($body === false || $code < 200 || $code >= 300) {
            return ['ok' => false, 'body' => '', 'error' => $err !== '' ? $err : ('http ' . $code)];
        }
        return ['ok' => true, 'body' => (string)$body, 'error' => ''];
    }

    /**
     * Load the feed config from a JSON file. Returns [] when missing/invalid.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function loadFeeds(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $feeds = $decoded['feeds'] ?? $decoded;
        return is_array($feeds) ? array_values(array_filter($feeds, 'is_array')) : [];
    }
}
