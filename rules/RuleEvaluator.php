<?php

/**
 * Pure evaluation of automation-rule conditions (Phase 8).
 *
 * A condition is {metric, op, value}; metrics come from the report aggregator
 * (clicks, uniques, cr, roi, profit, epc, bots, ...). Conditions are combined
 * with match = "all" (AND) or "any" (OR). No conditions means the rule is
 * unconditional (acts like a plain scheduled task). No I/O — fully testable.
 */
class RuleEvaluator
{
    private const OPS = [
        'gt'  => '>',  '>'  => '>',
        'gte' => '>=', '>=' => '>=',
        'lt'  => '<',  '<'  => '<',
        'lte' => '<=', '<=' => '<=',
        'eq'  => '==', '==' => '==', '=' => '==',
        'neq' => '!=', '!=' => '!=', '<>' => '!=',
    ];

    /**
     * @param array<string,float|int> $metrics
     * @param array<int,array<string,mixed>> $conditions
     */
    public static function evaluate(array $metrics, array $conditions, string $match = 'all'): bool
    {
        if ($conditions === []) {
            return true;
        }
        $any = strtolower($match) === 'any';
        foreach ($conditions as $cond) {
            $metric = (string)($cond['metric'] ?? '');
            $op = (string)($cond['op'] ?? '');
            $left = (float)($metrics[$metric] ?? 0);
            $right = (float)($cond['value'] ?? 0);
            $result = self::compare($left, $op, $right);
            if ($any && $result) {
                return true;
            }
            if (!$any && !$result) {
                return false;
            }
        }
        return !$any;
    }

    public static function compare(float $left, string $op, float $right): bool
    {
        $normalized = self::OPS[strtolower(trim($op))] ?? null;
        if ($normalized === null) {
            return false;
        }
        return match ($normalized) {
            '>'  => $left > $right,
            '>=' => $left >= $right,
            '<'  => $left < $right,
            '<=' => $left <= $right,
            '==' => $left === $right,
            '!=' => $left !== $right,
            default => false,
        };
    }
}
