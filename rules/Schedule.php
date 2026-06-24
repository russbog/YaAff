<?php

/**
 * Generic, data-driven schedule for automation rules (Phase 8).
 *
 * Accepts either an interval shorthand or a standard 5-field cron expression,
 * so a rule's cadence is pure configuration:
 *
 *   interval : "300" | "every:300" | "every:5m" | "every:2h" | "every:1d"
 *   macro    : "@hourly" | "@daily"/"@midnight" | "@weekly" | "@monthly" | "@yearly"
 *   cron     : "m h dom mon dow" with *, lists (a,b), ranges (a-b) and steps (*\/n)
 *   empty    : runs on every scheduler invocation
 *
 * Matching is pure given (now, lastRun), so it is fully unit-testable.
 */
class Schedule
{
    private bool $interval = false;
    private int $intervalSeconds = 0;
    private bool $always = false;
    private string $tz;
    /** @var array{0:int[],1:int[],2:int[],3:int[],4:int[]} expanded cron sets */
    private array $fields = [[], [], [], [], []];

    /** Inclusive [min,max] for each cron field: minute, hour, dom, month, dow. */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];

    private const MACROS = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    public function __construct(string $expr, string $tz = 'UTC')
    {
        $this->tz = $tz !== '' ? $tz : 'UTC';
        $expr = trim($expr);

        if ($expr === '') {
            $this->always = true;
            return;
        }

        $seconds = self::parseInterval($expr);
        if ($seconds !== null) {
            $this->interval = true;
            $this->intervalSeconds = max(1, $seconds);
            return;
        }

        $expr = self::MACROS[strtolower($expr)] ?? $expr;
        $this->parseCron($expr);
    }

    /**
     * Whether the rule is due at $now given when it last ran ($lastRun, unix
     * seconds; null = never). Interval schedules compare elapsed time; cron
     * schedules match calendar fields and never fire twice in the same minute.
     */
    public function isDue(int $now, ?int $lastRun = null): bool
    {
        if ($this->always) {
            return true;
        }
        if ($this->interval) {
            return $lastRun === null || ($now - $lastRun) >= $this->intervalSeconds;
        }
        if (!$this->matchesCalendar($now)) {
            return false;
        }
        if ($lastRun === null) {
            return true;
        }
        return $lastRun < ($now - ($now % 60));
    }

    private function matchesCalendar(int $now): bool
    {
        $dt = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($this->tz));
        $parts = [
            (int)$dt->format('i'),
            (int)$dt->format('G'),
            (int)$dt->format('j'),
            (int)$dt->format('n'),
            (int)$dt->format('w'),
        ];
        foreach ($parts as $i => $value) {
            if (!in_array($value, $this->fields[$i], true)) {
                return false;
            }
        }
        return true;
    }

    private static function parseInterval(string $expr): ?int
    {
        if (preg_match('/^\d+$/', $expr) === 1) {
            return (int)$expr;
        }
        if (preg_match('/^@?every[:\s]+(\d+)\s*([smhd])?$/i', $expr, $m) === 1) {
            $n = (int)$m[1];
            return match (strtolower($m[2] ?? 's')) {
                'm' => $n * 60,
                'h' => $n * 3600,
                'd' => $n * 86400,
                default => $n,
            };
        }
        return null;
    }

    private function parseCron(string $expr): void
    {
        $parts = preg_split('/\s+/', trim($expr)) ?: [];
        if (count($parts) !== 5) {
            // Unparseable: degrade to "never" rather than firing unexpectedly.
            $this->fields = [[], [], [], [], []];
            return;
        }
        foreach ($parts as $i => $part) {
            [$min, $max] = self::RANGES[$i];
            $this->fields[$i] = self::expandField((string)$part, $min, $max);
        }
    }

    /** @return int[] sorted, unique allowed values for one cron field */
    private static function expandField(string $field, int $min, int $max): array
    {
        $values = [];
        foreach (explode(',', $field) as $token) {
            $step = 1;
            if (str_contains($token, '/')) {
                [$token, $stepStr] = explode('/', $token, 2);
                $step = max(1, (int)$stepStr);
            }
            if ($token === '*' || $token === '') {
                $lo = $min;
                $hi = $max;
            } elseif (str_contains($token, '-')) {
                [$lo, $hi] = array_map('intval', explode('-', $token, 2));
            } else {
                $lo = $hi = (int)$token;
            }
            // Day-of-week: accept 7 as Sunday (0).
            if ($max === 6) {
                $lo = $lo === 7 ? 0 : $lo;
                $hi = $hi === 7 ? 0 : $hi;
            }
            if ($lo > $hi) {
                [$lo, $hi] = [$hi, $lo];
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                if ($v >= $min && $v <= $max) {
                    $values[$v] = true;
                }
            }
        }
        $out = array_keys($values);
        sort($out);
        return $out;
    }
}
