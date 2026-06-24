<?php

use PHPUnit\Framework\TestCase;

final class AutoUpdaterTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../admin/autoupdate.php';
        $this->assertFileExists($path);
        $this->source = (string) file_get_contents($path);
    }

    public function testUpdaterDownloadsMultipleconfigsBranchZip(): void
    {
        $this->assertStringContainsString('russbog/YaAff/archive/refs/heads/multipleconfigs.zip', $this->source);
        $this->assertStringContainsString('$this->downloadUrl = self::GITHUB_ZIP_URL;', $this->source);
    }

    public function testUpdaterCopiesFilesInsteadOfNoOp(): void
    {
        $this->assertStringNotContainsString('TODO: uncomment when ready', $this->source);
        $this->assertStringNotContainsString('// $this->recursiveCopy', $this->source);
        $this->assertStringContainsString('$copiedFiles = $this->recursiveCopyUpdate($extractedDir, $appRoot, $backupRoot);', $this->source);
        $this->assertStringContainsString('Successfully updated', $this->source);
    }

    public function testUpdaterPreservesRuntimeDataAndSecrets(): void
    {
        foreach (['settings.php', 'db', 'logs', 'ycclogs', 'tmp', 'caching', 'bases', 'backups', 'temp_update', 'fromfolder', '.git', '.env'] as $path) {
            $this->assertStringContainsString("'$path'", $this->source);
        }
        $this->assertStringContainsString('isPreservedPath', $this->source);
    }

    public function testUpdaterValidatesArchiveAndBacksUpChangedFiles(): void
    {
        foreach (['admin/version.txt', 'admin/autoupdate.php', 'index.php'] as $path) {
            $this->assertStringContainsString("'$path'", $this->source);
        }
        $this->assertStringContainsString('validateUpdateArchive($extractedDir)', $this->source);
        $this->assertStringContainsString('backupExistingFile($dstPath, $backupRoot, $childRelativePath)', $this->source);
        $this->assertStringContainsString('restoreBackup($backupRoot, $appRoot)', $this->source);
    }

    public function testVersionWasBumpedForDashboardUpdateDetection(): void
    {
        $version = trim((string) file_get_contents(__DIR__ . '/../admin/version.txt'));

        $this->assertSame('24.06.26', $version);
    }
}
