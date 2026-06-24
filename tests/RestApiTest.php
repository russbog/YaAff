<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../api/RestApi.php';

class RestApiTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;
    private RestApi $api;

    /** @var array{permissions:array<int,string>} */
    private array $admin;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytrest') . '.db';
        $this->driver = new SqliteDriver($this->path);
        foreach (['offers', 'groups', 'networks'] as $table) {
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
        $this->api = new RestApi(new EntityService($this->driver));
        $this->admin = ['permissions' => ['*']];
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testUnauthenticatedIs401(): void
    {
        $r = $this->api->handle('GET', ['offers'], [], null);
        $this->assertSame(401, $r['status']);
        $this->assertFalse($r['payload']['ok']);
    }

    public function testUnknownTypeIs404(): void
    {
        $r = $this->api->handle('GET', ['widgets'], [], $this->admin);
        $this->assertSame(404, $r['status']);
    }

    public function testCrudLifecycle(): void
    {
        // create
        $c = $this->api->handle('POST', ['offers'], ['name' => 'Promo', 'payout' => '5'], $this->admin);
        $this->assertSame(201, $c['status']);
        $id = $c['payload']['id'];
        $this->assertGreaterThan(0, $id);

        // list
        $l = $this->api->handle('GET', ['offers'], [], $this->admin);
        $this->assertSame(200, $l['status']);
        $this->assertCount(1, $l['payload']['items']);

        // get one
        $g = $this->api->handle('GET', ['offers', (string)$id], [], $this->admin);
        $this->assertSame('Promo', $g['payload']['item']['name']);
        $this->assertEquals(5, $g['payload']['item']['settings']['payout']);

        // patch only payout, name preserved
        $p = $this->api->handle('PATCH', ['offers', (string)$id], ['payout' => '9'], $this->admin);
        $this->assertSame(200, $p['status']);
        $after = $this->api->handle('GET', ['offers', (string)$id], [], $this->admin)['payload']['item'];
        $this->assertSame('Promo', $after['name']);
        $this->assertEquals(9, $after['settings']['payout']);

        // put replaces
        $this->api->handle('PUT', ['offers', (string)$id], ['name' => 'Renamed'], $this->admin);
        $this->assertSame('Renamed', $this->api->handle('GET', ['offers', (string)$id], [], $this->admin)['payload']['item']['name']);

        // delete
        $d = $this->api->handle('DELETE', ['offers', (string)$id], [], $this->admin);
        $this->assertSame(200, $d['status']);
        $this->assertTrue($d['payload']['deleted']);
        $this->assertCount(0, $this->api->handle('GET', ['offers'], [], $this->admin)['payload']['items']);
    }

    public function testViewerCannotMutate(): void
    {
        $viewer = ['permissions' => ['offers.view']];
        $this->assertSame(200, $this->api->handle('GET', ['offers'], [], $viewer)['status']);
        $w = $this->api->handle('POST', ['offers'], ['name' => 'X'], $viewer);
        $this->assertSame(403, $w['status']);
    }

    public function testWrongNamespacePermissionDenied(): void
    {
        $other = ['permissions' => ['networks.view']];
        $this->assertSame(403, $this->api->handle('GET', ['offers'], [], $other)['status']);
    }

    public function testMethodNotAllowed(): void
    {
        $r = $this->api->handle('OPTIONS', ['offers'], [], $this->admin);
        $this->assertSame(405, $r['status']);
    }

    public function testGetMissingIs404(): void
    {
        $this->assertSame(404, $this->api->handle('GET', ['offers', '999'], [], $this->admin)['status']);
    }
}
