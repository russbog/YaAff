<?php

//Language detection
require_once __DIR__ . '/bases/language.php';
//Device/Model/Browser/Platform detection
require_once __DIR__ . '/bases/device/autoload.php';
require_once __DIR__ . '/bases/device/ClientHints.php';
require_once __DIR__ . '/bases/device/DeviceDetector.php';
require_once __DIR__ . '/bases/device/Spyc.php';
//DeviceDetector caching
require_once __DIR__ . '/bases/device/Cache/Doctrine/MultiGetCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/MultiDeleteCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/MultiPutCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/Cache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/FlushableCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/ClearableCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/MultiOperationCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/CacheProvider.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/FileCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/PhpFileCache.php';
require_once __DIR__ . '/bases/device/Cache/Doctrine/CacheProvider.php';

require_once __DIR__ . '/bases/device/Cache/CacheInterface.php';
require_once __DIR__ . '/bases/device/Cache/DoctrineBridge.php';
//GEO and referer
require_once __DIR__ . '/bases/iputils.php';
require_once __DIR__ . '/bases/ipcountry.php';
//Extended filter helpers (masks, regex, search-engines, timetable)
require_once __DIR__ . '/filters/FilterFunctions.php';
//Offline auto-updated IP/UA blacklists (datacenter/proxy/bot)
require_once __DIR__ . '/bots/BlacklistStore.php';

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Cache\DoctrineBridge;
use DeviceDetector\Parser\Device\AbstractDeviceParser;

class FiltrationCore
{
    public string $block_reason = "";
    public array $matched_filters = [];
    public array $click_params = [];
    public int $campaignId = 0;
    public string $flowName = '';

    private static ?BlacklistStore $blacklistStore = null;

    private static function blacklistStore(): BlacklistStore
    {
        if (self::$blacklistStore === null) {
            self::$blacklistStore = new BlacklistStore(__DIR__ . '/bases/blacklists');
        }
        return self::$blacklistStore;
    }

    public function __construct(array $prefill = [])
    {
        DebugMethods::start("YWBCoreConstruct");
        $this->click_params = self::get_click_params($prefill);
        DebugMethods::stop("YWBCoreConstruct");
    }

    /** Context used by DB-backed filters (click_limit, uniqueness). */
    public function setContext(int $campaignId, string $flowName = ''): void
    {
        $this->campaignId = $campaignId;
        $this->flowName = $flowName;
    }

    private static function load_search_engines(): array
    {
        static $table = null;
        if ($table === null) {
            $path = __DIR__ . '/bases/searchengines.json';
            $table = is_readable($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
        }
        return $table;
    }

    public static function get_click_params(array $prefill = []): array
    {
        ClientHints::requestClientHints();
        $a = [];
        $a['ua'] = $prefill['tds_ua'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $a['referer'] = $prefill['tds_ref'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        $lang = $prefill['tds_lang'] ?? $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $a['lang'] = LanguageDetector::detect($lang);

        $clientHints = ClientHints::factory($prefill['tds_client_hints'] ?? $_SERVER);
        $dd = new DeviceDetector($a['ua'], $clientHints);

        DebugMethods::start("YWBCoreDeviceDetector");
        $cachePath = get_cache_path('devicesCache');
        $cacheDir = (DIRECTORY_SEPARATOR === '\\' ? preg_match('/^[A-Za-z]:/', $cachePath) : str_starts_with($cachePath, '/'))
            ? $cachePath . '/'
            : __DIR__ . '/' . $cachePath . '/';
        $phpFileCache = new Doctrine\Common\Cache\PhpFileCache($cacheDir);
        $dd->setCache(new DoctrineBridge($phpFileCache));
        $dd->parse();
        $clientInfo = $dd->getClient();
        $a['client'] = $clientInfo['name'];
        $a['clientver'] = $clientInfo['version'];
        DebugMethods::stop("YWBCoreDeviceDetector");

        $osInfo = $dd->getOs();
        $a['os'] = $osInfo['name'];
        $a['osver'] = $osInfo['version'];
        $a['device'] = $dd->getDeviceName();
        $a['brand'] = $dd->getBrandName();
        $a['model'] = $dd->getModel();
        $a['bot'] = ($dd->isBot() || self::blacklistStore()->matchesUa((string)$a['ua'])) ? 1 : 0;

        DebugMethods::start("YWBCoreMaxMind");
        $a['ip'] = getip($prefill['tds_ip'] ?? $_SERVER);
        $a['country'] = getcountry($a['ip']);
        $a['isp'] = getisp($a['ip']);
        $a['region'] = getregion($a['ip']);
        $a['city'] = getcity($a['ip']);
        $a['connection_type'] = getconnectiontype($a['ip']);
        DebugMethods::stop("YWBCoreMaxMind");

        $a['url'] = $prefill['tds_url'] ?? $_SERVER['REQUEST_URI'];
        //host - is where from the traffic comes
        $a['host'] = $prefill['tds_host'] ?? $_SERVER['HTTP_HOST'];
        //domain is where the traffic goes
        $a['domain'] = $_SERVER['HTTP_HOST'];
        parse_str($prefill['tds_qs'] ?? $_SERVER['QUERY_STRING'] ?? '', $a['qs']);

        $engines = self::load_search_engines();
        $a['search_engine'] = FilterFunctions::detectSearchEngine($a['referer'], $engines);
        $a['keyword'] = FilterFunctions::extractKeyword($a['referer'], $engines);
        $a['x_requested_with'] = $prefill['tds_xrw']
            ?? $_SERVER['HTTP_X_REQUESTED_WITH']
            ?? ($a['qs']['x_requested_with'] ?? '');
        $a['ts'] = isset($prefill['tds_ts']) ? (int)$prefill['tds_ts'] : time();
        return $a;
    }

    private function match_filters(bool $all, array|null $filters): bool
    {
        for ($i = 0; $i < count($filters); $i++) {
            $f = $filters[$i];
            if (!empty($f['condition'])) {//this is a filter group
                $fRes = $this->match_filters($f['condition'] === 'AND', $f['rules']);
            } else {
                $fRes = $this->match_filter($f);
            }
            if ($all && !$fRes) {
                return false;
            }
            if (!$all && $fRes) {
                return true;
            }
        }
        return $all; //if we are here, then for AND all are true and for OR all are false
    }


    /** Filter ids that map directly to a click parameter (string compare). */
    private const DIRECT_PARAMS = [
        'os', 'osver', 'device', 'brand', 'model', 'client', 'clientver',
        'country', 'lang', 'isp', 'referer', 'domain', 'host',
        'region', 'city', 'connection_type', 'search_engine', 'keyword',
        'x_requested_with'
    ];

    /** Aliases: filter id => click parameter key. */
    private const PARAM_ALIASES = [
        'ua' => 'ua',
        'useragent' => 'ua',
    ];

    /**
     * Resolve the comparable string value for a filter id. Falls back to the
     * query string so any campaign parameter (sub_id_n, creative_id, ...) is
     * filterable without hardcoding.
     */
    private function resolve_param_value(string $name): string
    {
        if ($name === 'site') {
            return (string)(parse_url((string)($this->click_params['referer'] ?? ''), PHP_URL_HOST) ?? '');
        }
        if (isset(self::PARAM_ALIASES[$name])) {
            return (string)($this->click_params[self::PARAM_ALIASES[$name]] ?? '');
        }
        if (in_array($name, self::DIRECT_PARAMS, true)) {
            return (string)($this->click_params[$name] ?? '');
        }
        return (string)($this->click_params['qs'][$name] ?? '');
    }

    private function match_filter(array $filter): bool
    {
        $val = $filter['value'] ?? '';
        $curParamName = $filter['id'];
        $operator = $filter['operator'] ?? 'in';

        switch ($curParamName) {
            case 'urlparam':
                if ($this->match_url_param_filter($filter)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'vpntor':
                $vpnDetected = $this->is_proxy_or_vpn($this->click_params['ip']);
                if (((int)$val === 0 && $vpnDetected) || ((int)$val === 1 && !$vpnDetected)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'bot':
                $isBot = (int)($this->click_params['bot'] ?? 0) === 1;
                if (((int)$val === 0 && $isBot) || ((int)$val === 1 && !$isBot)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'ipbase':
                $inBase = $this->is_ip_in_base($this->click_params['ip'], $val);
                if (($operator === 'in' && $inBase) || ($operator === 'not_in' && !$inBase)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'timetable':
                if ($this->match_timetable($filter)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'date_between':
                if ($this->match_date_between($filter)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'click_limit':
                if ($this->match_click_limit($filter)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            case 'uniqueness':
                if ($this->match_uniqueness($filter)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
            default:
                $paramValue = $this->resolve_param_value($curParamName);
                if ($this->operator((string)$val, $operator, $paramValue)) {
                    $this->matched_filters[] = $curParamName;
                    return true;
                }
                return false;
        }
    }

    private function match_timetable(array $filter): bool
    {
        $val = $filter['value'] ?? [];
        $rules = is_array($val) ? $val : (json_decode((string)$val, true) ?: []);
        $tz = (string)($filter['tz'] ?? $filter['timezone'] ?? 'UTC');
        $ts = (int)($this->click_params['ts'] ?? time());
        $inside = FilterFunctions::timetableMatches($rules, $ts, $tz);
        return (($filter['operator'] ?? 'in') === 'not_in') ? !$inside : $inside;
    }

    private function match_date_between(array $filter): bool
    {
        $val = $filter['value'] ?? [];
        $toTs = static function ($x): int {
            if (is_numeric($x)) {
                return (int)$x;
            }
            $t = strtotime((string)$x);
            return $t === false ? 0 : $t;
        };
        if (is_array($val)) {
            $from = $toTs($val['from'] ?? ($val[0] ?? 0));
            $to = $toTs($val['to'] ?? ($val[1] ?? 0));
        } else {
            $parts = array_map('trim', explode(',', (string)$val));
            $from = $toTs($parts[0] ?? 0);
            $to = $toTs($parts[1] ?? 0);
        }
        $ts = (int)($this->click_params['ts'] ?? time());
        $inside = FilterFunctions::dateBetween($ts, $from, $to);
        return (($filter['operator'] ?? 'in') === 'not_in') ? !$inside : $inside;
    }

    private function match_click_limit(array $filter): bool
    {
        global $db;
        $cfg = $filter['value'] ?? [];
        $cfg = is_array($cfg) ? $cfg : (json_decode((string)$cfg, true) ?: []);
        $limit = (int)($cfg['limit'] ?? 0);
        if ($limit <= 0 || !isset($db) || $this->campaignId <= 0) {
            return true; // misconfigured or no DB context => non-blocking
        }
        $ts = (int)($this->click_params['ts'] ?? time());
        $since = match (strtolower((string)($cfg['window'] ?? 'total'))) {
            'hour' => $ts - 3600,
            'day', '24h' => $ts - 86400,
            default => 0,
        };
        try {
            $count = $db->count_clicks($this->campaignId, $this->flowName, $since);
        } catch (Throwable $e) {
            return true;
        }
        return $count < $limit;
    }

    private function match_uniqueness(array $filter): bool
    {
        global $db;
        if (!isset($db) || $this->campaignId <= 0 || !function_exists('get_userid')) {
            return true;
        }
        $userid = get_userid();
        if ($userid === '') {
            return true;
        }
        try {
            $prev = $db->get_clicks_by_userid($userid, $this->campaignId);
        } catch (Throwable $e) {
            return true;
        }
        $isRepeat = !empty($prev);
        $want = strtolower((string)($filter['value'] ?? 'unique'));
        if ($want === '1' || $want === 'unique') {
            return !$isRepeat;
        }
        if ($want === '0' || $want === 'repeat') {
            return $isRepeat;
        }
        return true;
    }

    private function operator(string $val, string $operator, string $paramValue): bool
    {
        switch ($operator) {
            case 'param_in':
            case 'in':
                return FilterFunctions::valueMatchesAny($paramValue, $this->split_filter_values($val));
            case 'param_not_in':
            case 'not_in':
                return !FilterFunctions::valueMatchesAny($paramValue, $this->split_filter_values($val));
            case 'contains':
                foreach ($this->split_filter_values($val) as $value) {
                    if ($value !== '' && stripos($paramValue, $value) !== false) {
                        return true;
                    }
                }
                return false;
            case 'not_contains':
                foreach ($this->split_filter_values($val) as $value) {
                    if ($value !== '' && stripos($paramValue, $value) !== false) {
                        return false;
                    }
                }
                return true;
            case 'less_or_equal':
                return version_compare($paramValue, $val, '<=');
            case 'greater_or_equal':
                return version_compare($paramValue, $val, '>=');
            case 'greater_than':
                return FilterFunctions::numericCompare((float)$paramValue, 'greater_than', (float)$val);
            case 'less_than':
                return FilterFunctions::numericCompare((float)$paramValue, 'less_than', (float)$val);
            case 'equal':
                return FilterFunctions::valueMatches($paramValue, $val);
            case 'not_equal':
                return !FilterFunctions::valueMatches($paramValue, $val);
            case 'matches':
            case 'regex':
                $pattern = FilterFunctions::isRegex($val) ? $val : '/' . $val . '/i';
                return @preg_match($pattern, $paramValue) === 1;
            case 'not_matches':
                $pattern = FilterFunctions::isRegex($val) ? $val : '/' . $val . '/i';
                return @preg_match($pattern, $paramValue) !== 1;
            default:
                die("Operator $operator is not defined!");
        }
    }

    private function in_arrayi(string $needle, array $haystack): bool
    {
        foreach ($haystack as $item) {
            if (strcasecmp($needle, $item) === 0) {
                return true;
            }
        }
        return false;
    }

    private function split_filter_values(string $val): array
    {
        return array_map('trim', explode(',', $val));
    }

    private function match_url_param_filter(array $filter): bool
    {
        $val = $filter['value'] ?? '';
        $operator = $filter['operator'] ?? '';
        $clickQS = $this->click_params['qs'];
        $pName = is_array($val) ? (string) ($val[0] ?? '') : (string) $val;
        $paramExists = $pName !== '' && array_key_exists($pName, $clickQS);

        if ($operator === 'param_exists') {
            return $paramExists;
        }

        if ($operator === 'param_not_exists') {
            return !$paramExists;
        }

        if (!$paramExists) {
            return $operator === 'param_not_in';
        }

        $pValues = is_array($val) ? (string) ($val[1] ?? '') : '';
        return $this->operator($pValues, $operator, (string) $clickQS[$pName]);
    }

    public function click_matches_filters(array $filters): bool
    {
        try {
            DebugMethods::start("YWBCoreCheck");
            $this->matched_filters = [];
            $this->block_reason = '';

            if (
                empty($filters) ||
                !array_key_exists('rules', $filters) ||
                !is_array($filters['rules']) ||
                empty($filters['rules'])
            ) {
                $this->block_reason = 'no-filters';
                return true;
            }
            
            $allShouldMatch = $filters['condition'] === 'AND';
            $result = $this->match_filters($allShouldMatch, $filters['rules']);
            $this->block_reason = implode(', ', array_unique($this->matched_filters));
            return $result;
        } finally {
            DebugMethods::stop("YWBCoreCheck");
        }
    }

    private function is_proxy_or_vpn($ip): bool
    {
        //fast offline check against auto-updated datacenter/proxy/VPN blacklists
        if (self::blacklistStore()->matchesIp((string)$ip)) {
            return true;
        }

        //checks the commonly added by proxies header X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            $xip = explode(", ", $xip);
            if (count($xip) <= 1) {
                $xip = explode(",", $xip[0]);
            }
            if (!empty($xip[0])) {
                $xip = $xip[0];
            }
            if ($xip !== $ip) {
                return true;
            }
        }

        //perform checks using 3rd party services, SLOW
        $blackbox = $this->is_bad_by_blackbox($ip);
        if ($blackbox !== null) {
            return $blackbox;
        }
        $ipintel = $this->is_bad_by_ipintel($ip);
        return ($ipintel === null ? false : $ipintel);
    }

    private function is_bad_by_blackbox($ip): ?bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://blackbox.ipinfo.app/lookup/' . $ip);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            add_log('trace', "is_bad_by_blackbox: $ip from blackbox: $http_code");
            return null;
        }

        return $res === 'Y';
    }

    private function is_bad_by_ipintel($ip): ?bool
    {
        $contactEmail = "support@" . $_SERVER['HTTP_HOST'];
        $banOnProbability = 0.99;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_URL, "http://check.getipintel.net/check.php?ip=$ip&contact=$contactEmail&flags=m");

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno > 0) {
            add_error_log("is_bad_by_ipintel: $ip from ipintel: $errno - $error");
            return null;
        }

        if ($response === false) {
            add_error_log("is_bad_by_ipintel: $ip from ipintel: response is false");
            return null;
        }

        if ($response >= $banOnProbability) {
            return true;
        } else {
            if ($response < 0 || strcmp($response, "") == 0) {
                add_error_log("is_bad_by_ipintel: $ip from ipintel: response is incorrect");
                return null;
            }
            return false;
        }
    }

    private function is_ip_in_base($ip, $baseFileName): bool
    {
        $base_full_path = __DIR__ . "/bases/" . $baseFileName;
        if (!file_exists($base_full_path)) {
            return false;
        }
        $cidr = file($base_full_path, FILE_IGNORE_NEW_LINES);
        return IpUtils::checkIp($ip, $cidr);
    }
}
