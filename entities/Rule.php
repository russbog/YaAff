<?php

require_once __DIR__ . '/Entity.php';

/**
 * Automation rule (Phase 8). Generic and data-driven: a schedule, an optional
 * set of metric conditions and a list of actions. Empty conditions make the
 * rule an unconditional scheduled task (e.g. refresh blacklists, export report).
 *
 * Settings keys:
 *   enabled     bool    master on/off switch (default true)
 *   schedule    string  interval/cron/macro string (see {@see Schedule})
 *   timezone    string  IANA tz for cron evaluation (default UTC)
 *   campaign_id int     scope for metric window (0 = all campaigns)
 *   window      string  metric window: today|yesterday|1h|24h|7d|30d|<seconds>
 *   match       string  all|any condition combination (default all)
 *   conditions  array   list of {metric, op, value}
 *   actions     array   list of {type, ...params}
 *   last_run    int     unix epoch of last scheduler run (runtime bookkeeping)
 */
class Rule extends Entity
{
    public const TABLE = 'rules';

    public function enabled(): bool
    {
        return (bool)$this->get('enabled', true);
    }

    public function schedule(): string
    {
        return (string)$this->get('schedule', '');
    }

    public function timezone(): string
    {
        $tz = (string)$this->get('timezone', 'UTC');
        return $tz !== '' ? $tz : 'UTC';
    }

    public function campaignId(): int
    {
        return (int)$this->get('campaign_id', 0);
    }

    public function window(): string
    {
        $w = (string)$this->get('window', 'today');
        return $w !== '' ? $w : 'today';
    }

    public function match(): string
    {
        return strtolower((string)$this->get('match', 'all')) === 'any' ? 'any' : 'all';
    }

    /** @return array<int,array<string,mixed>> */
    public function conditions(): array
    {
        $c = $this->get('conditions', []);
        return is_array($c) ? array_values(array_filter($c, 'is_array')) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function actions(): array
    {
        $a = $this->get('actions', []);
        return is_array($a) ? array_values(array_filter($a, 'is_array')) : [];
    }

    public function lastRun(): ?int
    {
        $v = $this->get('last_run', null);
        return $v === null ? null : (int)$v;
    }
}
