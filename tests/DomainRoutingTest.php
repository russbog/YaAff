<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../entities/EntityRepository.php';

/**
 * Domain-pool routing behaviour: the default ("index page") campaign served at
 * a domain root, 404 interception of unmatched paths, and the per-domain
 * indexing toggle used by robots.txt.
 */
final class DomainRoutingTest extends TestCase
{
    private string $path;
    private Db $db;
    private EntityRepository $domains;
    private int $campaignId;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytdomroute') . '.db';
        $this->db = new Db(null, $this->path); // creates + migrates the sqlite file
        $this->domains = new EntityRepository(new SqliteDriver($this->path), 'domains', Domain::class);

        $this->campaignId = (int)$this->db->add_campaign('Default campaign');
        $settings = $this->db->get_campaign_settings($this->campaignId);
        $settings['identifier'] = 'known_path';
        $this->db->save_campaign_settings($this->campaignId, $settings);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['SCRIPT_NAME']);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    private function request(string $uri, string $host): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    private function seedDomain(string $host, array $settings): void
    {
        $this->domains->save(new Domain(['name' => $host, 'settings' => $settings]));
    }

    public function testRootServesDefaultCampaign(): void
    {
        $this->seedDomain('promo.example', ['campaign_id' => $this->campaignId]);
        $this->request('/', 'promo.example');

        $camp = $this->db->get_campaign_by_request();
        $this->assertIsArray($camp);
        $this->assertSame($this->campaignId, (int)$camp['id']);
    }

    public function testRootWithoutDefaultCampaignReturnsFalse(): void
    {
        $this->seedDomain('bare.example', []); // no default campaign
        $this->request('/', 'bare.example');

        $this->assertFalse($this->db->get_campaign_by_request());
    }

    public function testUnknownPathInterceptedWhenEnabled(): void
    {
        $this->seedDomain('promo.example', ['campaign_id' => $this->campaignId, 'intercept_404' => true]);
        $this->request('/totally-unknown', 'promo.example');

        $camp = $this->db->get_campaign_by_request();
        $this->assertIsArray($camp);
        $this->assertSame($this->campaignId, (int)$camp['id']);
    }

    public function testUnknownPathNotInterceptedWhenDisabled(): void
    {
        $this->seedDomain('promo.example', ['campaign_id' => $this->campaignId, 'intercept_404' => false]);
        $this->request('/totally-unknown', 'promo.example');

        $this->assertFalse($this->db->get_campaign_by_request());
    }

    public function testKnownIdentifierPathStillWinsOverIntercept(): void
    {
        $this->seedDomain('promo.example', ['campaign_id' => $this->campaignId, 'intercept_404' => true]);
        $this->request('/known_path', 'promo.example');

        $camp = $this->db->get_campaign_by_request();
        $this->assertIsArray($camp);
        $this->assertSame($this->campaignId, (int)$camp['id']);
        $this->assertSame('known_path', $camp['settings']['identifier']);
    }

    public function testWildcardDomainMatchesSubdomain(): void
    {
        $this->seedDomain('*.example', ['type' => 'wildcard', 'campaign_id' => $this->campaignId]);
        $this->request('/', 'sub.example');

        $camp = $this->db->get_campaign_by_request();
        $this->assertIsArray($camp);
        $this->assertSame($this->campaignId, (int)$camp['id']);
    }

    public function testIndexAllowedToggle(): void
    {
        $this->seedDomain('indexed.example', ['index_allowed' => true]);
        $this->seedDomain('hidden.example', ['index_allowed' => false]);

        $this->assertTrue($this->db->domain_index_allowed('indexed.example'));
        $this->assertFalse($this->db->domain_index_allowed('hidden.example'));
        $this->assertFalse($this->db->domain_index_allowed('unknown.example'));
    }
}
