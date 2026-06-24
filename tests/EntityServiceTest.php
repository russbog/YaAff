<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../entities/EntityService.php';

class EntityServiceTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;
    private EntityService $service;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytsvc') . '.db';
        $this->driver = new SqliteDriver($this->path);
        foreach (['offers', 'users', 'roles', 'groups', 'networks'] as $table) {
            $this->driver->exec(
                "CREATE TABLE $table (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    group_id INTEGER,
                    settings TEXT NOT NULL DEFAULT '{}',
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )"
            );
        }
        $this->service = new EntityService($this->driver);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testUnknownTypeThrows404(): void
    {
        try {
            $this->service->list('nope');
            $this->fail('expected exception');
        } catch (EntityServiceException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testSaveRequiresName(): void
    {
        $this->expectException(EntityServiceException::class);
        $this->service->save('offers', ['name' => '   ']);
    }

    public function testCreateCoercesFieldsAndResolvesGroup(): void
    {
        $id = $this->service->save('offers', [
            'name' => 'Promo',
            'group' => 'Q3',
            'payout' => '12.5',
            'cap_daily' => '100',
            'network_id' => '7',
            'multi_values' => "a=1\nb=2",
        ]);
        $this->assertGreaterThan(0, $id);

        $item = $this->service->get('offers', $id);
        $this->assertSame('Promo', $item['name']);
        $this->assertSame('Q3', $item['group']);
        $this->assertSame(12.5, $item['settings']['payout']);
        $this->assertSame(100, $item['settings']['cap_daily']);
        $this->assertSame(7, $item['settings']['network_id']);
        $this->assertSame(['a' => '1', 'b' => '2'], $item['settings']['multi_values']);

        // Same group name reuses the existing group row.
        $id2 = $this->service->save('offers', ['name' => 'Promo2', 'group' => 'Q3']);
        $g1 = $this->service->get('offers', $id)['group'];
        $g2 = $this->service->get('offers', $id2)['group'];
        $this->assertSame($g1, $g2);
        $this->assertCount(1, Repositories::groups($this->driver)->findAll());
    }

    public function testUpdateKeepsId(): void
    {
        $id = $this->service->save('offers', ['name' => 'A', 'payout' => '1']);
        $same = $this->service->save('offers', ['id' => $id, 'name' => 'B', 'payout' => '2']);
        $this->assertSame($id, $same);
        $this->assertSame('B', $this->service->get('offers', $id)['name']);
        $this->assertCount(1, $this->service->list('offers'));
    }

    public function testPasswordHashedAndRedacted(): void
    {
        $id = $this->service->save('users', ['name' => 'bob', 'password' => 'secret']);
        $item = $this->service->get('users', $id);
        $this->assertSame(EntityService::REDACTED, $item['settings']['password']);

        // Hash actually stored and verifiable.
        $stored = Repositories::users($this->driver)->find($id);
        $this->assertTrue($stored->verifyPassword('secret'));

        // Re-saving with the redaction placeholder must not overwrite the hash.
        $this->service->save('users', ['id' => $id, 'name' => 'bob', 'password' => EntityService::REDACTED]);
        $this->assertTrue(Repositories::users($this->driver)->find($id)->verifyPassword('secret'));

        // Blank password keeps existing hash too.
        $this->service->save('users', ['id' => $id, 'name' => 'bob', 'password' => '']);
        $this->assertTrue(Repositories::users($this->driver)->find($id)->verifyPassword('secret'));
    }

    public function testDelete(): void
    {
        $id = $this->service->save('offers', ['name' => 'X']);
        $this->assertTrue($this->service->delete('offers', $id));
        $this->assertNull($this->service->get('offers', $id));
    }
}
