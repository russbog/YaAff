<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bases/ipcountry.php';

final class GeoIpFallbackTest extends TestCase
{
    public function testCountryFallsBackToUnknownWhenDatabaseIsMissing(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../bases/GeoLite2-Country.mmdb');
        $this->assertSame('Unknown', getcountry('8.8.8.8'));
    }

    public function testIspFallsBackToUnknownWhenDatabaseIsMissing(): void
    {
        $this->assertFileDoesNotExist(__DIR__ . '/../bases/GeoLite2-ASN.mmdb');
        $this->assertSame('Unknown', getisp('8.8.8.8'));
    }

    public function testLookupMissesReturnUnknownInsteadOfThrowing(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../bases/ipcountry.php');

        $this->assertStringContainsString('catch (ANFException $exception)', $source);
        $this->assertStringContainsString('GetCountry AddressNotFoundException', $source);
        $this->assertStringContainsString('GetISP AddressNotFoundException', $source);
    }
}
