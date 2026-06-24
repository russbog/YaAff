<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../entities/EntityRepository.php';

class EntityRepositoryTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytrepo') . '.db';
        $this->driver = new SqliteDriver($this->path);
        $this->driver->exec(
            "CREATE TABLE networks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                group_id INTEGER,
                settings TEXT NOT NULL DEFAULT '{}',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )"
        );
        $this->repo = new EntityRepository($this->driver, 'networks');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testSaveInsertsAndAssignsIdAndTimestamps(): void
    {
        $e = new Entity();
        $e->name = 'PartnerCo';
        $e->settings = ['postback_url' => 'https://x/{clickid}', 'currency' => 'USD'];

        $saved = $this->repo->save($e);

        $this->assertSame(1, $saved->id);
        $this->assertNotNull($saved->created_at);
        $this->assertSame($saved->created_at, $saved->updated_at);
    }

    public function testFindRoundTripsSettingsJson(): void
    {
        $e = new Entity();
        $e->name = 'PartnerCo';
        $e->settings = ['k' => 'v', 'nested' => ['a' => 1]];
        $id = $this->repo->save($e)->id;

        $found = $this->repo->find($id);
        $this->assertNotNull($found);
        $this->assertSame('PartnerCo', $found->name);
        $this->assertSame('v', $found->settings['k']);
        $this->assertSame(1, $found->settings['nested']['a']);
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->find(999));
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $e = new Entity();
        $e->name = 'MixedCase';
        $this->repo->save($e);

        $found = $this->repo->findByName('mixedcase');
        $this->assertNotNull($found);
        $this->assertSame('MixedCase', $found->name);
    }

    public function testUpdatePreservesCreatedAt(): void
    {
        $e = new Entity();
        $e->name = 'Orig';
        $saved = $this->repo->save($e);
        $createdAt = $saved->created_at;

        $saved->created_at = 1; // attempt to tamper; repo must ignore on update
        $saved->name = 'Updated';
        $saved->settings = ['x' => 2];
        $this->repo->save($saved);

        $reloaded = $this->repo->find($saved->id);
        $this->assertSame('Updated', $reloaded->name);
        $this->assertSame(2, $reloaded->settings['x']);
        $this->assertSame($createdAt, $reloaded->created_at);
    }

    public function testFindAllWithWhereOrderAndLimit(): void
    {
        foreach ([['a', 1], ['b', 1], ['c', 2]] as [$name, $group]) {
            $e = new Entity();
            $e->name = $name;
            $e->group_id = $group;
            $this->repo->save($e);
        }

        $group1 = $this->repo->findAll(['group_id' => 1], 'name', 'ASC');
        $this->assertCount(2, $group1);
        $this->assertSame('a', $group1[0]->name);

        $desc = $this->repo->findAll([], 'name', 'DESC', 1);
        $this->assertCount(1, $desc);
        $this->assertSame('c', $desc[0]->name);
    }

    public function testFindAllWithNullCondition(): void
    {
        $e1 = new Entity();
        $e1->name = 'no-group';
        $this->repo->save($e1);
        $e2 = new Entity();
        $e2->name = 'has-group';
        $e2->group_id = 5;
        $this->repo->save($e2);

        $orphans = $this->repo->findAll(['group_id' => null]);
        $this->assertCount(1, $orphans);
        $this->assertSame('no-group', $orphans[0]->name);
    }

    public function testCount(): void
    {
        $this->assertSame(0, $this->repo->count());
        $e = new Entity();
        $e->name = 'one';
        $this->repo->save($e);
        $this->assertSame(1, $this->repo->count());
    }

    public function testDelete(): void
    {
        $e = new Entity();
        $e->name = 'temp';
        $id = $this->repo->save($e)->id;

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
        $this->assertFalse($this->repo->delete($id));
    }

    public function testInvalidTableNameRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EntityRepository($this->driver, 'bad name; DROP TABLE x');
    }

    public function testUnknownColumnRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->findAll(['nope' => 1]);
    }
}
