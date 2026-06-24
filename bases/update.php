<?php

require_once __DIR__ . "/../debug.php";
require_once __DIR__ . "/../settings.php";
require_once __DIR__ . "/../admin/password.php";
require_once __DIR__ . "/../logging.php";

function downloadAndExtractMaxMindDB($licenseKey, $directory, $editionIds): string
{
    $result = "";
    foreach ($editionIds as $editionId) {
        $result .= downloadMaxMindDB($licenseKey, $directory, $editionId) . "\n";
    }
    save_update_version();
    save_geoip_source('MaxMind GeoLite2');
    return $result;
}

function downloadMaxMindDB($licenseKey, $directory, $editionId): string
{
    try {
        $url = "https://download.maxmind.com/app/geoip_download?edition_id=$editionId&license_key=$licenseKey&suffix=tar.gz";
        add_log('trace', "Starting download for $editionId from $url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); // Ignore SSL host verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // Ignore SSL peer verification

        $output = curl_exec($ch);
        if ($output === false) {
            $error = curl_error($ch);
            curl_close($ch);
            error_log("$editionId cURL Error: $error");
            return "$editionId cURL Error: $error";
        }

        $fileName = $directory . '/' . $editionId . '.tar.gz';
        file_put_contents($fileName, $output);
        add_log('trace', "Downloaded $editionId database to $fileName");

        // Decompress the file
        $phar = new PharData($fileName);
        $phar->decompress();
        add_log('trace', "Decompressed $fileName");

        $tarName = str_replace('.gz', '', $fileName);
        $phar = new PharData($tarName);
        foreach ($phar as $folder) {
            if (!$folder->isDir()) {
                continue;
            }
            add_log('trace', "Processing folder: " . $folder->getPathname());
            $subPhar = new PharData($folder->getPathname());
            foreach ($subPhar as $file) {
                if (preg_match('/\.mmdb$/', $file->getFilename())) {
                    // Extract file manually to avoid creating directory
                    $content = file_get_contents($file->getPathname());
                    file_put_contents($directory . '/' . $file->getFilename(), $content);
                    add_log('trace', "Extracted " . $file->getFilename() . " to $directory");
                    break; // Assuming there's only one .mmdb file
                }
            }
        }

        // Delete the tar.gz and tar files
        unlink($fileName);
        unlink($tarName);
        add_log('trace', "Cleaned up temporary files for $editionId");

        curl_close($ch);
        return "$editionId processed!";
    } catch (Exception $e) {
        add_error_log("MaxMind bases update: Error processing $editionId: " . $e->getMessage());
        return "$editionId Error: " . $e->getMessage();
    }
}

function downloadDbIpLiteDatabases(string $directory): string
{
    $result = "";
    $result .= downloadDbIpLiteDatabase($directory, 'country', 'GeoLite2-Country') . "\n";
    $result .= downloadDbIpLiteDatabase($directory, 'asn', 'GeoLite2-ASN') . "\n";
    if (is_readable("$directory/GeoLite2-Country.mmdb") && is_readable("$directory/GeoLite2-ASN.mmdb")) {
        save_update_version();
        save_geoip_source('DB-IP Lite (CC BY 4.0) - https://db-ip.com');
        $result .= "Using DB-IP Lite under CC BY 4.0. Keep attribution visible in YaAff.\n";
    }
    return $result;
}

function downloadDbIpLiteDatabase(string $directory, string $kind, string $targetName): string
{
    $months = [
        (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m'),
        (new DateTime('first day of last month', new DateTimeZone('UTC')))->format('Y-m'),
    ];

    foreach ($months as $month) {
        $url = "https://download.db-ip.com/free/dbip-$kind-lite-$month.mmdb.gz";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'YaAff geobases updater');
        $output = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($output === false || $status >= 400) {
            add_log('trace', "DB-IP Lite $kind download failed for $month: HTTP $status $error");
            continue;
        }

        $content = gzdecode($output);
        if ($content === false) {
            add_log('trace', "DB-IP Lite $kind archive for $month could not be decompressed");
            continue;
        }

        file_put_contents("$directory/$targetName.mmdb", $content);
        return "$targetName processed from DB-IP Lite $month!";
    }

    return "$targetName DB-IP Lite download failed; GeoIP will fall back to Unknown.";
}

function save_update_version(): void
{
    $dateObj = new DateTime();
    $formattedDate = $dateObj->format('d.m.y');
    file_put_contents(__DIR__ . "/update.txt", $formattedDate);
}

function save_geoip_source(string $source): void
{
    file_put_contents(__DIR__ . "/source.txt", $source);
}

function dbip_lite_databases_available(): bool
{
    return is_readable(__DIR__ . '/GeoLite2-Country.mmdb')
        && is_readable(__DIR__ . '/GeoLite2-ASN.mmdb');
}

function send_update_result($msg, $error = false): void
{
    $res = ["result" => $msg, "error" => $error];
    header('Content-type: application/json');
    http_response_code(200);
    echo json_encode($res);
}

$passOk = check_password(false);
if (!$passOk) {
    send_update_result("Password check not passed!", true);
    exit;
}

if (empty($cloSettings["maxMindKey"])) {
    $result = downloadDbIpLiteDatabases(__DIR__);
    $result .= dbip_lite_databases_available()
        ? "MaxMind key not set; DB-IP Lite fallback is active."
        : "MaxMind key not set and DB-IP Lite fallback failed; GeoIP will remain Unknown, traffic routing will continue.";
    send_update_result($result, false);
    exit;
}

$editionIds = ['GeoLite2-ASN', 'GeoLite2-Country', 'GeoLite2-City'];
$result = downloadAndExtractMaxMindDB($cloSettings["maxMindKey"], __DIR__, $editionIds);
send_update_result($result, false);