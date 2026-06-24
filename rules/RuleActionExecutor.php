<?php

require_once __DIR__ . '/../reports/ReportExporter.php';
require_once __DIR__ . '/RuleScheduler.php';

/**
 * Executes the actions of an automation rule (Phase 8).
 *
 * Actions are dispatched by type to pluggable handlers, so later phases add new
 * behaviours (e.g. Phase 9 notifications) by registering a handler — no changes
 * here. Built-in handlers cover campaign control, weight correction, report
 * export and blacklist refresh. Each handler returns
 * ['type'=>string,'ok'=>bool,'message'=>string]; handler exceptions are caught
 * so one failing action never aborts the rest (deterministic, like the rest of
 * the tracker's graceful-degradation policy).
 */
class RuleActionExecutor
{
    /** @var array<string,callable(array<string,mixed>,array<string,mixed>):array<string,mixed>> */
    private array $handlers = [];

    public function __construct()
    {
        $this->registerBuiltins();
    }

    /**
     * @param callable(array<string,mixed>,array<string,mixed>):array<string,mixed> $handler
     */
    public function register(string $type, callable $handler): void
    {
        $this->handlers[strtolower($type)] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[strtolower($type)]);
    }

    /**
     * @param array<string,mixed> $action  {type, ...params}
     * @param array<string,mixed> $ctx     {db, rule, metrics}
     * @return array<string,mixed>
     */
    public function execute(array $action, array $ctx): array
    {
        $type = strtolower((string)($action['type'] ?? ''));
        if (!isset($this->handlers[$type])) {
            return ['type' => $type, 'ok' => false, 'message' => 'unknown action'];
        }
        try {
            $result = ($this->handlers[$type])($action, $ctx);
        } catch (Throwable $e) {
            return ['type' => $type, 'ok' => false, 'message' => 'error: ' . $e->getMessage()];
        }
        $result['type'] = $type;
        $result['ok'] = (bool)($result['ok'] ?? false);
        $result['message'] = (string)($result['message'] ?? '');
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @param array<string,mixed> $ctx
     * @return array<int,array<string,mixed>>
     */
    public function executeAll(array $actions, array $ctx): array
    {
        $results = [];
        foreach ($actions as $action) {
            if (is_array($action)) {
                $results[] = $this->execute($action, $ctx);
            }
        }
        return $results;
    }

    private function registerBuiltins(): void
    {
        $this->register('pause_campaign', fn($a, $c) => $this->setCampaignEnabled($a, $c, false));
        $this->register('resume_campaign', fn($a, $c) => $this->setCampaignEnabled($a, $c, true));
        $this->register('set_flow_weight', fn($a, $c) => $this->setFlowWeight($a, $c));
        $this->register('export_report', fn($a, $c) => $this->exportReport($a, $c));
        $this->register('update_blacklists', fn($a, $c) => $this->updateBlacklists($a, $c));
        $this->register('log', fn($a, $c) => ['ok' => true, 'message' => (string)($a['message'] ?? 'log')]);
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function setCampaignEnabled(array $a, array $c, bool $enabled): array
    {
        $db = $c['db'] ?? null;
        $campId = (int)($a['campaign_id'] ?? $this->ruleCampaignId($c));
        if (!is_object($db) || $campId <= 0) {
            return ['ok' => false, 'message' => 'no campaign'];
        }
        $settings = $db->get_campaign_settings($campId);
        if (!is_array($settings)) {
            return ['ok' => false, 'message' => 'campaign not found'];
        }
        $settings['enabled'] = $enabled;
        $db->save_campaign_settings($campId, $settings);
        return ['ok' => true, 'message' => ($enabled ? 'resumed' : 'paused') . ' campaign ' . $campId];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function setFlowWeight(array $a, array $c): array
    {
        $db = $c['db'] ?? null;
        $campId = (int)($a['campaign_id'] ?? $this->ruleCampaignId($c));
        $flowName = (string)($a['flow'] ?? '');
        $weight = max(0, (int)($a['weight'] ?? 0));
        if (!is_object($db) || $campId <= 0 || $flowName === '') {
            return ['ok' => false, 'message' => 'missing campaign/flow'];
        }
        $settings = $db->get_campaign_settings($campId);
        if (!is_array($settings)) {
            return ['ok' => false, 'message' => 'campaign not found'];
        }
        $changed = 0;
        foreach (['white', 'black'] as $section) {
            if (!isset($settings[$section]['flows']) || !is_array($settings[$section]['flows'])) {
                continue;
            }
            foreach ($settings[$section]['flows'] as &$flow) {
                if (is_array($flow) && (string)($flow['name'] ?? '') === $flowName) {
                    $flow['weight'] = $weight;
                    $changed++;
                }
            }
            unset($flow);
        }
        if ($changed === 0) {
            return ['ok' => false, 'message' => "flow '$flowName' not found"];
        }
        $db->save_campaign_settings($campId, $settings);
        return ['ok' => true, 'message' => "set weight $weight on '$flowName' (x$changed)"];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function exportReport(array $a, array $c): array
    {
        $db = $c['db'] ?? null;
        if (!is_object($db)) {
            return ['ok' => false, 'message' => 'no db'];
        }
        $campId = (int)($a['campaign_id'] ?? $this->ruleCampaignId($c));
        $fields = is_array($a['fields'] ?? null) ? $a['fields'] : ['clicks', 'conversion', 'revenue', 'profit'];
        $groupBy = is_array($a['group_by'] ?? null) ? $a['group_by'] : ['date'];
        $format = strtolower((string)($a['format'] ?? 'csv')) === 'json' ? 'json' : 'csv';
        $tz = (string)($a['timezone'] ?? 'UTC');
        [$start, $end] = $this->windowRange((string)($a['range'] ?? 'today'), $tz);

        $tree = $db->get_statistics($fields, $groupBy, $campId, (string)$start, (string)$end, $tz, [], []);
        $rows = ReportExporter::flattenTree(is_array($tree) ? $tree : [], $groupBy);
        $payload = $format === 'json' ? ReportExporter::toJson($rows) : ReportExporter::toCsv($rows);

        $output = (string)($a['output'] ?? '');
        if ($output === '') {
            return ['ok' => true, 'message' => 'exported ' . count($rows) . ' rows (no output path)'];
        }
        $output = $this->expandOutput($output, $format);
        $dir = dirname($output);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ok = file_put_contents($output, $payload) !== false;
        return ['ok' => $ok, 'message' => ($ok ? 'wrote ' : 'failed writing ') . $output];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function updateBlacklists(array $a, array $c): array
    {
        $feedsPath = (string)($a['feeds'] ?? (__DIR__ . '/../bases/blacklists/feeds.json'));
        if (!is_file($feedsPath)) {
            return ['ok' => false, 'message' => 'feeds file not found'];
        }
        require_once __DIR__ . '/../bots/BlacklistUpdater.php';
        $store = new BlacklistStore(dirname($feedsPath));
        $updater = new BlacklistUpdater($store);
        $results = $updater->update(BlacklistUpdater::loadFeeds($feedsPath));
        $ok = 0;
        foreach ($results as $r) {
            if (!empty($r['ok']) && empty($r['skipped'])) {
                $ok++;
            }
        }
        return ['ok' => true, 'message' => "refreshed $ok/" . count($results) . ' feeds'];
    }

    /** @param array<string,mixed> $c */
    private function ruleCampaignId(array $c): int
    {
        $rule = $c['rule'] ?? null;
        return $rule instanceof Rule ? $rule->campaignId() : 0;
    }

    /** @return array{0:int,1:int} */
    private function windowRange(string $range, string $tz): array
    {
        return RuleScheduler::resolveWindow($range, $tz, time());
    }

    private function expandOutput(string $path, string $format): string
    {
        $ext = $format === 'json' ? 'json' : 'csv';
        return strtr($path, [
            '{date}' => date('Y-m-d'),
            '{ts}' => (string)time(),
            '{ext}' => $ext,
        ]);
    }
}
