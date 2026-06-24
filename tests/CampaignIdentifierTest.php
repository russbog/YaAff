<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/db.php';

final class CampaignIdentifierTest extends TestCase
{
    private string $path;
    private Db $db;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytcamp') . '.db';
        $this->db = new Db(null, $this->path);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT'], $_SERVER['SCRIPT_NAME']);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testNewCampaignGetsUniqueIdentifier(): void
    {
        $firstId = $this->db->add_campaign('First');
        $secondId = $this->db->add_campaign('Second');

        $first = $this->db->get_campaign_settings((int)$firstId);
        $second = $this->db->get_campaign_settings((int)$secondId);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $first['identifier']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $second['identifier']);
        $this->assertNotSame($first['identifier'], $second['identifier']);
    }

    public function testRequestPathIdentifierRoutesOnAnyHost(): void
    {
        $campaignId = (int)$this->db->add_campaign('Path campaign');
        $settings = $this->db->get_campaign_settings($campaignId);
        $settings['identifier'] = 'thank_you';
        $settings['domains'] = ['old-domain.example'];
        $this->assertTrue($this->db->save_campaign_settings($campaignId, $settings));

        $_SERVER['REQUEST_URI'] = '/thank_you?same=parameters';
        $_SERVER['HTTP_HOST'] = 'new-domain.example';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $campaign = $this->db->get_campaign_by_request();

        $this->assertIsArray($campaign);
        $this->assertSame($campaignId, (int)$campaign['id']);
        $this->assertSame('thank_you', $campaign['settings']['identifier']);
    }

    public function testUnknownPathDoesNotFallBackToDomainMatch(): void
    {
        $campaignId = (int)$this->db->add_campaign('Legacy domain campaign');
        $settings = $this->db->get_campaign_settings($campaignId);
        $settings['identifier'] = 'known_path';
        $settings['domains'] = ['new-domain.example'];
        $this->assertTrue($this->db->save_campaign_settings($campaignId, $settings));

        $_SERVER['REQUEST_URI'] = '/unknown_path?same=parameters';
        $_SERVER['HTTP_HOST'] = 'new-domain.example';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->assertFalse($this->db->get_campaign_by_request());
    }

    public function testIdentifierCanBeCheckedForUniqueness(): void
    {
        $campaignId = (int)$this->db->add_campaign('Unique campaign');
        $settings = $this->db->get_campaign_settings($campaignId);
        $settings['identifier'] = 'dsv34g3g';
        $this->assertTrue($this->db->save_campaign_settings($campaignId, $settings));

        $this->assertTrue($this->db->campaign_identifier_is_unique('dsv34g3g', $campaignId));
        $this->assertFalse($this->db->campaign_identifier_is_unique('dsv34g3g'));
    }

    public function testClonedCampaignGetsNewIdentifier(): void
    {
        $campaignId = (int)$this->db->add_campaign('Original');
        $settings = $this->db->get_campaign_settings($campaignId);

        $cloneId = (int)$this->db->clone_campaign($campaignId);
        $cloneSettings = $this->db->get_campaign_settings($cloneId);

        $this->assertNotSame($settings['identifier'], $cloneSettings['identifier']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $cloneSettings['identifier']);
    }
}
