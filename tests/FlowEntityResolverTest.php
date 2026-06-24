<?php

use PHPUnit\Framework\TestCase;

$GLOBALS['cloSettings'] = ['debug' => false];
if (!defined('YELLOWTDS_NO_DB_BOOTSTRAP')) {
    define('YELLOWTDS_NO_DB_BOOTSTRAP', true);
}

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../entities/FlowEntityResolver.php';
require_once __DIR__ . '/../campaign.php';

class FlowEntityResolverTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;
    private EntityRepository $offers;
    private EntityRepository $landings;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytflow') . '.db';
        $this->driver = new SqliteDriver($this->path);
        foreach (['offers', 'landings'] as $table) {
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
        $this->offers = Repositories::offers($this->driver);
        $this->landings = Repositories::landings($this->driver);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    private function saveOffer(string $name, array $settings): int
    {
        $o = new Offer();
        $o->name = $name;
        $o->settings = $settings;
        return $this->offers->save($o)->id;
    }

    private function saveLanding(string $name, array $settings): int
    {
        $l = new Landing();
        $l->name = $name;
        $l->settings = $settings;
        return $this->landings->save($l)->id;
    }

    public function testOfferRefExpandsToRedirectUrl(): void
    {
        $id = $this->saveOffer('Promo', ['type' => 'redirect', 'url' => 'https://offer.test/{clickid}']);
        $step = StepSettings::fromArray(['action' => 'redirect', 'offers' => [$id]]);

        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);

        $this->assertCount(1, $step->redirectUrls);
        $this->assertSame('https://offer.test/{clickid}', $step->redirectUrls[0]['url']);
        $this->assertSame('Promo', $step->redirectUrls[0]['label']);
    }

    public function testLocalLandingRefExpandsToFolder(): void
    {
        $id = $this->saveLanding('LP1', ['type' => 'local', 'path' => 'landings/lp1']);
        $step = StepSettings::fromArray(['action' => 'folder', 'landings' => [$id]]);

        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);

        $this->assertSame(['landings/lp1'], $step->folderNames);
    }

    public function testRemoteLandingRefExpandsToRedirectUrl(): void
    {
        $id = $this->saveLanding('Remote', ['type' => 'remote', 'url' => 'https://lp.test/']);
        $step = StepSettings::fromArray(['action' => 'redirect', 'landings' => [$id]]);

        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);

        $this->assertCount(1, $step->redirectUrls);
        $this->assertSame('https://lp.test/', $step->redirectUrls[0]['url']);
    }

    public function testExpansionIsAdditiveAndDeduplicates(): void
    {
        $id = $this->saveOffer('Promo', ['type' => 'redirect', 'url' => 'https://dup.test/']);
        $step = StepSettings::fromArray([
            'action' => 'redirect',
            'redirect' => ['urls' => [['url' => 'https://dup.test/', 'label' => 'existing']], 'type' => 302],
            'offers' => [$id, $id],
        ]);

        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);

        $this->assertCount(1, $step->redirectUrls);
        $this->assertSame('existing', $step->redirectUrls[0]['label']);
    }

    public function testMissingEntityIdIsSkipped(): void
    {
        $step = StepSettings::fromArray(['action' => 'redirect', 'offers' => [9999]]);
        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);
        $this->assertSame([], $step->redirectUrls);
    }

    public function testNoRefsLeavesStepUntouched(): void
    {
        $step = StepSettings::fromArray(['action' => 'folder', 'folders' => ['white1']]);
        FlowEntityResolver::expandStep($step, $this->offers, $this->landings);
        $this->assertSame(['white1'], $step->folderNames);
        $this->assertSame([], $step->redirectUrls);
    }

    public function testStepSettingsRoundTripsEntityRefs(): void
    {
        $step = StepSettings::fromArray(['action' => 'redirect', 'offers' => [1, 2], 'landings' => [3]]);
        $json = $step->jsonSerialize();
        $this->assertSame([1, 2], $json['offers']);
        $this->assertSame([3], $json['landings']);

        $reparsed = StepSettings::fromArray($json);
        $this->assertSame([1, 2], $reparsed->offerIds);
        $this->assertSame([3], $reparsed->landingIds);
    }
}
