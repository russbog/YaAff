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

function save_update_version(): void
{
    $dateObj = new DateTime();
    $formattedDate = $dateObj->format('d.m.y');
    file_put_contents(__DIR__ . "/update.txt", $formattedDate);
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
    send_update_result("MaxMind key not set, edit 'settings.php'!", true);
    exit;
}

$editionIds = ['GeoLite2-ASN', 'GeoLite2-Country', 'GeoLite2-City'];
$result = downloadAndExtractMaxMindDB($cloSettings["maxMindKey"], __DIR__, $editionIds);
send_update_result($result, false);