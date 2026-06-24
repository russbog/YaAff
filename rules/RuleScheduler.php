<?php

require_once __DIR__ . '/../db/drivers/DbDriver.php';
require_once __DIR__ . '/../entities/Repositories.php';
require_once __DIR__ . '/../entities/Rule.php';
require_once __DIR__ . '/../reports/DashboardQuery.php';
require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/RuleEvaluator.php';
require_once __DIR__ . '/RuleActionExecutor.php';

/**
 * Automation engine + cron scheduler (Phase 8).
 *
 * On each invocation it loads enabled rules, fires those whose {@see Schedule}
 * is due, evaluates their metric conditions against a {@see DashboardQuery}
 * window and runs the matching rule's actions via {@see RuleActionExecutor}.
 * Every run is audited in rule_log and the rule's last_run is advanced.
 *
 * The engine is decoupled from the request path: it only needs a DbDriver, the
 * Db facade (for campaign mutations / statistics) and an executor, so it is
 * unit-testable with a seeded in-memory driver and fake action handlers.
 */
class RuleScheduler
{
    private DbDriver $driver;
    private DashboardQuery $query;
    private RuleActionExecutor $executor;
    private EntityRepository $rules;
    /** @var object|null Db facade used by campaign/report actions. */
    private $db;

    public function __construct(DbDriver $driver, RuleActionExecutor $executor, $db = null)
    {
        $this->driver = $driver;
        $this->executor = $executor;
        $this->db = $db;
        $this->query = new DashboardQuery($driver);
        $this->rules = Repositories::for($driver, Rule::TABLE, Rule::class);
    }

    /**
     * Run all due rules and return one result record per rule processed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(int $now): array
    {
        $results = [];
        foreach ($this->rules->findAll([], 'id', 'ASC') as $entity) {
            /** @var Rule $rule */
            $rule = $entity;
            if (!$rule->enabled()) {
                continue;
            }
            $schedule = new Schedule($rule->schedule(), $rule->timezone());
            if (!$schedule->isDue($now, $rule->lastRun())) {
                continue;
            }
            $results[] = $this->fire($rule, $now);
        }
        return $results;
    }

    /**
     * Evaluate one rule's conditions and run its actions when matched. Always
     * advances last_run and writes an audit row.
     *
     * @return array<string,mixed>
     */
    public function fire(Rule $rule, int $now): array
    {
        [$start, $end] = self::resolveWindow($rule->window(), $rule->timezone(), $now);
        $metrics = $this->query->summary($rule->campaignId(), $start, $end);
        $matched = RuleEvaluator::evaluate($metrics, $rule->conditions(), $rule->match());

        $actionResults = [];
        if ($matched) {
            $actionResults = $this->executor->executeAll($rule->actions(), [
                'db' => $this->db,
                'rule' => $rule,
                'metrics' => $metrics,
            ]);
        }

        $rule->set('last_run', $now);
        $this->rules->save($rule);
        $this->log((int)$rule->id, $now, $matched, $metrics, $actionResults);

        return [
            'rule_id' => (int)$rule->id,
            'name' => $rule->name,
            'matched' => $matched,
            'actions' => $actionResults,
        ];
    }

    /**
     * Resolve a window string into [startTs, endTs].
     * Supports: today | yesterday | 1h | 24h | 7d | 30d | <seconds>.
     *
     * @return array{0:int,1:int}
     */
    public static function resolveWindow(string $range, string $tz, int $now): array
    {
        try {
            $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
        } catch (Throwable $e) {
            $zone = new DateTimeZone('UTC');
        }
        $nowDt = (new DateTimeImmutable('@' . $now))->setTimezone($zone);

        if (is_numeric($range)) {
            return [$now - (int)$range, $now];
        }

        switch (strtolower($range)) {
            case 'today':
                return [$nowDt->setTime(0, 0, 0)->getTimestamp(), $now];
            case 'yesterday':
                $start = $nowDt->modify('-1 day')->setTime(0, 0, 0);
                $end = $nowDt->setTime(0, 0, 0);
                return [$start->getTimestamp(), $end->getTimestamp()];
            case '1h':
                return [$now - 3600, $now];
            case '24h':
                return [$now - 86400, $now];
            case '30d':
                return [$now - 2592000, $now];
            case '7d':
            default:
                return [$now - 604800, $now];
        }
    }

    /**
     * @param array<string,float|int> $metrics
     * @param array<int,array<string,mixed>> $actions
     */
    private function log(int $ruleId, int $now, bool $matched, array $metrics, array $actions): void
    {
        $this->driver->insert(
            "INSERT INTO rule_log (rule_id, ran_at, matched, metrics, actions, message)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                [$ruleId, DbDriver::INT],
                [$now, DbDriver::INT],
                [$matched ? 1 : 0, DbDriver::INT],
                [json_encode($metrics), DbDriver::TEXT],
                [json_encode($actions), DbDriver::TEXT],
                [$this->summarize($matched, $actions), DbDriver::TEXT],
            ]
        );
    }

    /** @param array<int,array<string,mixed>> $actions */
    private function summarize(bool $matched, array $actions): string
    {
        if (!$matched) {
            return 'conditions not met';
        }
        if ($actions === []) {
            return 'matched; no actions';
        }
        $parts = [];
        foreach ($actions as $a) {
            $parts[] = ($a['type'] ?? '?') . ': ' . ($a['ok'] ? 'ok' : 'fail') . ' ' . ($a['message'] ?? '');
        }
        return implode(' | ', $parts);
    }
}
