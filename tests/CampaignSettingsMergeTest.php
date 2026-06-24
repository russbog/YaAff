<?php

use PHPUnit\Framework\TestCase;

final class CampaignSettingsMergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('mergeSettingsRecursive')) {
            $_REQUEST = [];
            $GLOBALS['cloSettings']['adminPassword'] = 'test';
            ob_start();
            require __DIR__ . '/../admin/campeditor.php';
            ob_end_clean();
        }
    }

    public function testNestedAssociativeSettingsInsideListsKeepTheirKeys(): void
    {
        $incoming = [
            'black' => [
                'flows' => [[
                    'name' => 'Flow 1',
                    'steps' => [[
                        'action' => 'redirect',
                        'redirect' => [
                            'urls' => [['url' => 'https://google.com', 'label' => 'google.com']],
                            'type' => 302,
                        ],
                        'weights' => [100],
                    ]],
                ]],
            ],
        ];

        $merged = mergeSettingsRecursive([], $incoming);
        $step = $merged['black']['flows'][0]['steps'][0];

        $this->assertSame('https://google.com', $step['redirect']['urls'][0]['url']);
        $this->assertSame('google.com', $step['redirect']['urls'][0]['label']);
        $this->assertSame(302, $step['redirect']['type']);
    }
    public function testFlowStepWeightsAreNormalizedInsideFlowLists(): void
    {
        $flows = [[
            'name' => 'Flow 1',
            'steps' => [[
                'action' => 'redirect',
                'weights' => [0],
            ]],
        ]];

        $normalized = normalize_flow_step_weights($flows);

        $this->assertSame([100], $normalized[0]['steps'][0]['weights']);
    }

}
