<?php

use PHPUnit\Framework\TestCase;

final class RuntimeEntrypointsTest extends TestCase
{
    public function testDocumentedRuntimeEntrypointsExist(): void
    {
        $root = dirname(__DIR__);
        $entrypoints = [
            'index.php',
            'js/index.php',
            'phpconnect.php',
            'postback.php',
            'send.php',
            'next.php',
            'updateparams.php',
        ];

        foreach ($entrypoints as $entrypoint) {
            $this->assertFileExists($root . '/' . $entrypoint, $entrypoint);
        }
    }
}
