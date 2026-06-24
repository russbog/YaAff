<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/db.php';

final class LeadStorageTest extends TestCase
{
    private string $path;
    private Db $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytlead') . '.db';
        $this->db = new Db(null, $this->path);
        $this->db->driver()->insert(
            'INSERT INTO clicks (clickid, time, ip, userid, status) VALUES (?, ?, ?, ?, ?)',
            [
                ['lead-click-1', DbDriver::TEXT],
                [time(), DbDriver::INT],
                ['127.0.0.1', DbDriver::TEXT],
                ['user-1', DbDriver::TEXT],
                ['Click', DbDriver::TEXT],
            ]
        );
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testAddLeadStoresArrayPayloadAsJson(): void
    {
        $payload = [
            'name' => 'E2E Tester',
            'phone' => '+10000000000',
            'nested' => ['source' => 'form'],
        ];

        $this->assertTrue($this->db->add_lead('lead-click-1', $payload));

        $row = $this->db->driver()->selectOne(
            'SELECT status, leaddata FROM clicks WHERE clickid = ?',
            [['lead-click-1', DbDriver::TEXT]]
        );

        $this->assertSame('Lead', $row['status']);
        $this->assertSame($payload, json_decode($row['leaddata'], true));
    }
}
