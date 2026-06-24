<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../db/drivers/SqliteDriver.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../rules/RuleScheduler.php';

class RuleSchedulerTest extends TestCase
{
    private string $path;
    private SqliteDriver $driver;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ytrules') . '.db';
        $this->driver = new SqliteDriver($this->path);
        // Schema needed by the scheduler + DashboardQuery window.
        $this->driver->exec("CREATE TABLE rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, group_id INTEGER, settings TEXT NOT NULL DEFAULT '{}', created_at INTEGER, updated_at INTEGER)");
        $this->driver->exec("CREATE TABLE rule_log (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INTEGER, ran_at INTEGER, matched INTEGER, metrics TEXT, actions TEXT, message TEXT)");
        $this->driver->exec('CREATE TABLE clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, userid TEXT, country TEXT, flow TEXT, cost NUMERIC DEFAULT 0)');
        $this->driver->exec('CREATE TABLE blocked (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, reason TEXT)');
        $this->driver->exec('CREATE TABLE conversions (id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER, time INTEGER, status TEXT, revenue NUMERIC DEFAULT 0)');
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @unlink($this->path . $s);
        }
    }

    private function addRule(string $name, array $settings): Rule
    {
        $repo = Repositories::rules($this->driver);
        $rule = new Rule(['name' => $name]);
        foreach ($settings as $k => $v) {
            $rule->set($k, $v);
        }
        /** @var Rule $saved */
        $saved = $repo->save($rule);
        return $saved;
    }

    private function spyExecutor(array &$calls): RuleActionExecutor
    {
        $exec = new RuleActionExecutor();
        $exec->register('spy', function (array $a, array $c) use (&$calls): array {
            $calls[] = $a;
            return ['ok' => true, 'message' => 'spied'];
        });
        return $exec;
    }

    public function testFiresDueRuleWithMatchingConditionAndLogs(): void
    {
        $now = 1700000000;
        $this->driver->insert('INSERT INTO clicks (campaign_id, time, userid, cost) VALUES (?, ?, ?, ?)', [[0, DbDriver::INT], [$now - 10, DbDriver::INT], ['u1', DbDriver::TEXT], [0.0, DbDriver::FLOAT]]);

        $this->addRule('low traffic', [
            'enabled' => true,
            'schedule' => '',
            'window' => '24h',
            'conditions' => [['metric' => 'clicks', 'op' => 'lt', 'value' => 10]],
            'actions' => [['type' => 'spy', 'flow' => 'x']],
        ]);

        $calls = [];
        $scheduler = new RuleScheduler($this->driver, $this->spyExecutor($calls));
        $results = $scheduler->run($now);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['matched']);
        $this->assertCount(1, $calls, 'action handler invoked once');

        $log = $this->driver->select('SELECT * FROM rule_log');
        $this->assertCount(1, $log);
        $this->assertSame(1, (int)$log[0]['matched']);
    }

    public function testSkipsDisabledRule(): void
    {
        $this->addRule('off', ['enabled' => false, 'schedule' => '', 'actions' => [['type' => 'spy']]]);
        $calls = [];
        $scheduler = new RuleScheduler($this->driver, $this->spyExecutor($calls));
        $this->assertSame([], $scheduler->run(1700000000));
        $this->assertCount(0, $calls);
    }

    public function testSkipsRuleNotYetDue(): void
    {
        $rule = $this->addRule('interval', ['enabled' => true, 'schedule' => '3600', 'actions' => []]);
        $rule->set('last_run', 1700000000 - 100);
        Repositories::rules($this->driver)->save($rule);

        $calls = [];
        $scheduler = new RuleScheduler($this->driver, $this->spyExecutor($calls));
        $this->assertSame([], $scheduler->run(1700000000), '100s < 3600s -> not due');
    }

    public function testUnmatchedRuleAdvancesLastRunAndSkipsActions(): void
    {
        $now = 1700000000;
        // No clicks -> clicks = 0, condition requires clicks > 100 -> no match.
        $rule = $this->addRule('needs traffic', [
            'enabled' => true,
            'schedule' => '',
            'window' => '24h',
            'conditions' => [['metric' => 'clicks', 'op' => 'gt', 'value' => 100]],
            'actions' => [['type' => 'spy']],
        ]);

        $calls = [];
        $scheduler = new RuleScheduler($this->driver, $this->spyExecutor($calls));
        $results = $scheduler->run($now);

        $this->assertFalse($results[0]['matched']);
        $this->assertCount(0, $calls, 'actions not run when unmatched');

        $reloaded = Repositories::rules($this->driver)->find((int)$rule->id);
        $this->assertSame($now, $reloaded->get('last_run'));
    }

    public function testUnknownActionReportedAsFailure(): void
    {
        $exec = new RuleActionExecutor();
        $res = $exec->execute(['type' => 'does_not_exist'], []);
        $this->assertFalse($res['ok']);
        $this->assertSame('unknown action', $res['message']);
    }

    public function testActionExceptionIsCaught(): void
    {
        $exec = new RuleActionExecutor();
        $exec->register('boom', function (): array {
            throw new RuntimeException('kaboom');
        });
        $res = $exec->execute(['type' => 'boom'], []);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('kaboom', $res['message']);
    }

    public function testResolveWindowToday(): void
    {
        $now = strtotime('2024-05-10 13:30:00 UTC');
        [$start, $end] = RuleScheduler::resolveWindow('today', 'UTC', $now);
        $this->assertSame(strtotime('2024-05-10 00:00:00 UTC'), $start);
        $this->assertSame($now, $end);
    }

    public function testResolveWindowNumericSeconds(): void
    {
        [$start, $end] = RuleScheduler::resolveWindow('3600', 'UTC', 1700000000);
        $this->assertSame(1700000000 - 3600, $start);
        $this->assertSame(1700000000, $end);
    }
}
