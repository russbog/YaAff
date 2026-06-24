<?php
require_once(__DIR__ . '/password.php');

class AutoUpdater {
    private const GITHUB_REPO = 'russbog/YaAff';
    private const GITHUB_BRANCH = 'multipleconfigs';
    private const GITHUB_API_URL = 'https://api.github.com/repos/russbog/YaAff/contents/admin/version.txt?ref=multipleconfigs';
    private const GITHUB_ZIP_URL = 'https://github.com/russbog/YaAff/archive/refs/heads/multipleconfigs.zip';
    private const VERSION_FILE = __DIR__ . '/version.txt';
    private const SETTINGS_FILE = __DIR__ . '/../settings.php';
    private const BACKUP_DIR = __DIR__ . '/../backups';
    private const UPDATE_DIR = __DIR__ . '/../temp_update';
    private const PRESERVED_PATHS = [
        'settings.php',
        'db',
        'logs',
        'ycclogs',
        'tmp',
        'caching',
        'bases',
        'backups',
        'temp_update',
        'fromfolder',
        '.git',
        '.env',
    ];
    private const REQUIRED_UPDATE_FILES = [
        'admin/version.txt',
        'admin/autoupdate.php',
        'index.php',
    ];

    private $currentVersion;
    private $latestVersion;
    private $downloadUrl;

    public function __construct() {
        $this->currentVersion = trim(file_get_contents(self::VERSION_FILE));
    }

    public function checkForUpdates(): bool {
        try {
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: PHP',
                        'Accept: application/vnd.github.v3+json'
                    ]
                ]
            ];
            $context = stream_context_create($opts);
            $response = file_get_contents(self::GITHUB_API_URL, false, $context);
            
            if ($response === false) {
                throw new Exception("Failed to fetch version information");
            }

            $fileInfo = json_decode($response, true);
            if (!$fileInfo || !isset($fileInfo['content'])) {
                throw new Exception("Invalid version file information");
            }

            $this->latestVersion = trim(base64_decode($fileInfo['content']));

            $latestTimestamp = $this->convertVersionToTimestamp($this->latestVersion);
            $currentTimestamp = $this->convertVersionToTimestamp($this->currentVersion);

            return $latestTimestamp > $currentTimestamp;
        } catch (Exception $e) {
            error_log("Update check failed: " . $e->getMessage());
            return false;
        }
    }

    private function convertVersionToTimestamp(string $version): int {
        $parts = explode('.', $version);
        if (count($parts) !== 3) {
            throw new Exception("Invalid version format");
        }
        return mktime(0, 0, 0, $parts[1], $parts[0], 2000 + intval($parts[2]));
    }

    public function update(): array {
        $result = ['success' => false, 'message' => ''];
        $appRoot = dirname(__DIR__);
        $backupRoot = self::BACKUP_DIR . '/update_' . date('Y-m-d_H-i-s');

        try {
            $this->ensureDirectory(self::BACKUP_DIR);
            if (file_exists(self::UPDATE_DIR)) {
                $this->recursiveDelete(self::UPDATE_DIR);
            }
            $this->ensureDirectory(self::UPDATE_DIR);
            $this->ensureDirectory($backupRoot);

            $settingsBackup = $backupRoot . '/settings.php';
            if (!copy(self::SETTINGS_FILE, $settingsBackup)) {
                throw new Exception("Failed to backup settings.php");
            }

            $zipFile = self::UPDATE_DIR . '/update.zip';
            $this->downloadUrl = self::GITHUB_ZIP_URL;
            if (!$this->downloadFile($this->downloadUrl, $zipFile)) {
                throw new Exception("Failed to download update");
            }

            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new Exception("Failed to open update archive");
            }
            $zip->extractTo(self::UPDATE_DIR);
            $zip->close();

            $extractedDirs = glob(self::UPDATE_DIR . '/*', GLOB_ONLYDIR);
            $extractedDir = $extractedDirs[0] ?? null;
            if (!$extractedDir) {
                throw new Exception("Failed to locate extracted files");
            }

            $this->validateUpdateArchive($extractedDir);
            $copiedFiles = $this->recursiveCopyUpdate($extractedDir, $appRoot, $backupRoot);
            $this->recursiveDelete(self::UPDATE_DIR);

            $result['success'] = true;
            $result['message'] = "Successfully updated " . $copiedFiles . " files from " . self::GITHUB_REPO . ':' . self::GITHUB_BRANCH . ". Backup: " . basename($backupRoot);
        } catch (Exception $e) {
            if (isset($backupRoot) && is_dir($backupRoot)) {
                $this->restoreBackup($backupRoot, $appRoot);
            }
            if (file_exists(self::UPDATE_DIR)) {
                $this->recursiveDelete(self::UPDATE_DIR);
            }
            $result['message'] = "Update failed: " . $e->getMessage();
        }

        return $result;
    }

    private function downloadFile(string $url, string $path): bool {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PHP',
                    'Accept: application/vnd.github.v3+json'
                ]
            ]
        ];
        $context = stream_context_create($opts);
        $content = file_get_contents($url, false, $context);
        return $content !== false && file_put_contents($path, $content) !== false;
    }

    private function validateUpdateArchive(string $extractedDir): void {
        foreach (self::REQUIRED_UPDATE_FILES as $file) {
            if (!is_file($extractedDir . '/' . $file)) {
                throw new Exception("Update archive is missing required file: $file");
            }
        }
    }

    private function recursiveCopyUpdate(string $src, string $dst, string $backupRoot, string $relativePath = ''): int {
        $this->ensureDirectory($dst);
        $dir = opendir($src);
        if ($dir === false) {
            throw new Exception("Failed to open update directory: $src");
        }

        $copied = 0;
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $childRelativePath = ltrim($relativePath . '/' . $file, '/');
            if ($this->isPreservedPath($childRelativePath)) continue;

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                $copied += $this->recursiveCopyUpdate($srcPath, $dstPath, $backupRoot, $childRelativePath);
                continue;
            }

            if (file_exists($dstPath)) {
                $this->backupExistingFile($dstPath, $backupRoot, $childRelativePath);
            }
            if (!copy($srcPath, $dstPath)) {
                throw new Exception("Failed to copy update file: $childRelativePath");
            }
            $copied++;
        }
        closedir($dir);
        return $copied;
    }

    private function isPreservedPath(string $relativePath): bool {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        foreach (self::PRESERVED_PATHS as $preservedPath) {
            if ($relativePath === $preservedPath || str_starts_with($relativePath, $preservedPath . '/')) {
                return true;
            }
        }
        return false;
    }

    private function backupExistingFile(string $path, string $backupRoot, string $relativePath): void {
        $backupPath = $backupRoot . '/' . $relativePath;
        $this->ensureDirectory(dirname($backupPath));
        if (!copy($path, $backupPath)) {
            throw new Exception("Failed to backup existing file: $relativePath");
        }
    }

    private function restoreBackup(string $backupRoot, string $appRoot): void {
        $this->restoreBackupDirectory($backupRoot, $appRoot);
    }

    private function restoreBackupDirectory(string $backupDir, string $targetDir): void {
        $dir = opendir($backupDir);
        if ($dir === false) return;

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $backupPath = $backupDir . '/' . $file;
            $targetPath = $targetDir . '/' . $file;

            if (is_dir($backupPath)) {
                $this->ensureDirectory($targetPath);
                $this->restoreBackupDirectory($backupPath, $targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            copy($backupPath, $targetPath);
        }
        closedir($dir);
    }

    private function ensureDirectory(string $dir): void {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new Exception("Failed to create directory: $dir");
        }
    }

    private function recursiveDelete(string $dir): void {
        if (!file_exists($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function getCurrentVersion(): string {
        return $this->currentVersion;
    }

    public function getLatestVersion(): string {
        return $this->latestVersion;
    }
}

// Handle update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!check_password(false)){
        $response = ['success' => false, 'message' => 'Incorrect password'];
    }
    else {
        $updater = new AutoUpdater();
        $response = ['success' => false, 'message' => ''];

        switch ($_POST['action']) {
            case 'check':
                $hasUpdate = $updater->checkForUpdates();
                $response = [
                    'success' => true,
                    'hasUpdate' => $hasUpdate,
                    'version' => $hasUpdate ? $updater->getLatestVersion() : $updater->getCurrentVersion()
                ];
                break;

            case 'update':
                $result = $updater->update();
                $response = [
                    'success' => $result['success'],
                    'message' => $result['message']
                ];
                break;

            default:
                $response['message'] = 'Invalid action';
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} else {
    http_response_code(405);
    echo 'Method Not Allowed';
}
?>