<?php
require_once __DIR__ . '/../logging.php';
if (!extension_loaded('maxminddb')) {
    require_once __DIR__ . '/geoip2.phar';
}
use GeoIp2\Database\Reader as GeoIp2Reader;
use GeoIp2\Exception\AddressNotFoundException as ANFException;

function getip(array|null $headers = null): string
{
    if (is_null($headers)) $headers = $_SERVER;
    
    $remoteAddr = (string)($headers['REMOTE_ADDR'] ?? '');

    $trustedCfIp = get_ip_from_cloudfare($headers);
    if ($trustedCfIp !== null) {
        return $trustedCfIp;
    }

    if ($remoteAddr === '::1' || !is_public_ip($remoteAddr))
        return '109.124.224.100'; //for debugging
    
    return $remoteAddr;
}

function is_public_ip(string $ip): bool
{
    return filter_var(
    $ip,
    FILTER_VALIDATE_IP,
    FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === $ip;
}

function is_cloudflare_ip(string $ip): bool
{
    if (!is_public_ip($ip)) {
        return false;
    }

    try {
        $isp = getisp($ip);
    } catch (Throwable $exception) {
        return false;
    }

    return is_string($isp) && str_contains(strtolower($isp), 'cloudflare');
}

function get_ip_from_cloudfare(array $headers): ?string
{
    $remoteAddr = trim((string)($headers['REMOTE_ADDR'] ?? ''));
    $cfip = trim((string)($headers['HTTP_CF_CONNECTING_IP'] ?? ''));

    if ($cfip === '' || !is_public_ip($cfip)) {
        return null;
    }

    if (is_cloudflare_ip($remoteAddr)) {
        return $cfip;
    }

    add_log("bases", "Fake CloudflareIP: $cfip, RemoteAddr: $remoteAddr");
    return null;
}

function getcountry(string $ip): string
{
    if ($ip === 'Unknown') return 'Unknown';

    if ($ip === '::1' || $ip === '127.0.0.1')
        $ip = '31.177.76.70'; //for debugging

    if (use_maxminddb_extension()) {
        $record = read_maxminddb_record('GeoLite2-Country.mmdb', $ip);
        if ($record === null) {
            add_log("bases", "GetCountry AddressNotFoundException: $ip");
            return 'Unknown';
        }
        return (string)($record['country']['iso_code'] ?? 'Unknown');
    }

    $reader = open_geoip_reader('GeoLite2-Country.mmdb');
    try {
        $record = $reader->country($ip);
        return $record->country->isoCode;
    } catch (ANFException $exception) {
        add_log("bases", "GetCountry AddressNotFoundException: $ip");
        return 'Unknown';
    }
}

function geoip_db_available(string $fileName): bool
{
    return is_readable(__DIR__ . '/' . $fileName);
}

/**
 * Most-specific subdivision (region/state) ISO code from the GeoLite2 City DB.
 * Returns '' when the City DB is not installed or the address is not found,
 * so region filtering degrades gracefully instead of throwing.
 */
function getregion(string $ip): string
{
    if ($ip === 'Unknown') return '';
    if ($ip === '::1' || $ip === '127.0.0.1') $ip = '31.177.76.70';
    if (!geoip_db_available('GeoLite2-City.mmdb')) return '';

    try {
        if (use_maxminddb_extension()) {
            $record = read_maxminddb_record('GeoLite2-City.mmdb', $ip);
            $subs = $record['subdivisions'] ?? [];
            $last = is_array($subs) && !empty($subs) ? end($subs) : null;
            return (string)($last['iso_code'] ?? '');
        }
        $reader = open_geoip_reader('GeoLite2-City.mmdb');
        $record = $reader->city($ip);
        return (string)($record->mostSpecificSubdivision->isoCode ?? '');
    } catch (Throwable $exception) {
        add_log("bases", "GetRegion error: $ip");
        return '';
    }
}

/** City name from the GeoLite2 City DB; '' when unavailable. */
function getcity(string $ip): string
{
    if ($ip === 'Unknown') return '';
    if ($ip === '::1' || $ip === '127.0.0.1') $ip = '31.177.76.70';
    if (!geoip_db_available('GeoLite2-City.mmdb')) return '';

    try {
        if (use_maxminddb_extension()) {
            $record = read_maxminddb_record('GeoLite2-City.mmdb', $ip);
            return (string)($record['city']['names']['en'] ?? '');
        }
        $reader = open_geoip_reader('GeoLite2-City.mmdb');
        $record = $reader->city($ip);
        return (string)($record->city->name ?? '');
    } catch (Throwable $exception) {
        add_log("bases", "GetCity error: $ip");
        return '';
    }
}

/** Connection type from the GeoIP2 Connection-Type/ISP DB; '' when unavailable. */
function getconnectiontype(string $ip): string
{
    if ($ip === 'Unknown') return '';
    if ($ip === '::1' || $ip === '127.0.0.1') $ip = '31.177.76.70';
    foreach (['GeoIP2-Connection-Type.mmdb', 'GeoIP2-ISP.mmdb'] as $dbName) {
        if (!geoip_db_available($dbName)) continue;
        try {
            if (use_maxminddb_extension()) {
                $record = read_maxminddb_record($dbName, $ip);
                $ct = $record['connection_type'] ?? '';
                if ($ct !== '') return (string)$ct;
            }
        } catch (Throwable $exception) {
            add_log("bases", "GetConnectionType error: $ip");
        }
    }
    return '';
}

function getisp(string $ip)
{
    if ($ip === 'Unknown') return 'Unknown';
    if ($ip === '::1' || $ip === '127.0.0.1')
        $ip = '31.177.76.70'; //for debugging

    if (use_maxminddb_extension()) {
        $record = read_maxminddb_record('GeoLite2-ASN.mmdb', $ip);
        if ($record === null) {
            add_log("bases", "GetISP AddressNotFoundException: $ip");
            return 'Unknown';
        }
        return $record['autonomous_system_organization'] ?? 'Unknown';
    }

    $reader = open_geoip_reader('GeoLite2-ASN.mmdb');
    try {
        $record = $reader->asn($ip);
        return $record->autonomousSystemOrganization;
    } catch (ANFException $exception) {
        add_log("bases", "GetISP AddressNotFoundException: $ip");
        return 'Unknown';
    }
}

function open_geoip_reader(string $fileName): object
{
    $path = __DIR__ . '/' . $fileName;
    if (!is_readable($path)) {
        throw new RuntimeException("Configuration error: GeoIP database is missing or unreadable: $path. Set maxMindKey in settings.php and run bases/update.php, or upload $fileName manually.");
    }

    try {
        return new GeoIp2Reader($path);
    } catch (Throwable $exception) {
        throw new RuntimeException("Configuration error: GeoIP database cannot be opened: $path. " . $exception->getMessage(), 0, $exception);
    }
}

function use_maxminddb_extension(): bool
{
    return extension_loaded('maxminddb') && class_exists('\\MaxMind\\Db\\Reader', false);
}

function read_maxminddb_record(string $fileName, string $ip): ?array
{
    $path = __DIR__ . '/' . $fileName;
    if (!is_readable($path)) {
        throw new RuntimeException("Configuration error: GeoIP database is missing or unreadable: $path. Set maxMindKey in settings.php and run bases/update.php, or upload $fileName manually.");
    }

    try {
        $reader = new \MaxMind\Db\Reader($path);
        $record = $reader->get($ip);
        $reader->close();
        return is_array($record) ? $record : null;
    } catch (Throwable $exception) {
        throw new RuntimeException("Configuration error: GeoIP database cannot be opened: $path. " . $exception->getMessage(), 0, $exception);
    }
}
