<?php

/**
 * Keitaro-style flow (stream) selection.
 *
 * Flows have a type:
 *   - forced  : evaluated first, in order; first matching flow wins outright.
 *   - regular : matching flows participate in a weighted/equal random split.
 *   - default : fallback; first matching default flow wins when no regular flow
 *               was selected.
 *
 * Selection is pure given a candidate list and an RNG, so it is unit-testable.
 * Each candidate is ['index'=>int, 'matches'=>bool, 'type'=>string, 'weight'=>int].
 */
class FlowSelector
{
    /**
     * @param array $candidates list of candidate descriptors
     * @param callable|null $rng function(int $min, int $max): int (defaults to mt_rand)
     * @return int|null chosen original flow index, or null if nothing matched
     */
    public static function select(array $candidates, ?callable $rng = null): ?int
    {
        $rng ??= fn(int $min, int $max): int => mt_rand($min, $max);

        foreach ($candidates as $c) {
            if (self::typeOf($c) === 'forced' && !empty($c['matches'])) {
                return (int)$c['index'];
            }
        }

        $regular = [];
        foreach ($candidates as $c) {
            if (self::typeOf($c) === 'regular' && !empty($c['matches'])) {
                $regular[] = $c;
            }
        }
        if (!empty($regular)) {
            return self::weightedPick($regular, $rng);
        }

        foreach ($candidates as $c) {
            if (self::typeOf($c) === 'default' && !empty($c['matches'])) {
                return (int)$c['index'];
            }
        }

        return null;
    }

    private static function typeOf(array $c): string
    {
        $t = strtolower((string)($c['type'] ?? 'regular'));
        return in_array($t, ['forced', 'regular', 'default'], true) ? $t : 'regular';
    }

    private static function weightedPick(array $items, callable $rng): int
    {
        $total = 0;
        foreach ($items as $it) {
            $w = (int)($it['weight'] ?? 1);
            $total += max(0, $w);
        }
        if ($total <= 0) {
            return (int)$items[$rng(0, count($items) - 1)]['index'];
        }
        $r = $rng(1, $total);
        $acc = 0;
        foreach ($items as $it) {
            $acc += max(0, (int)($it['weight'] ?? 1));
            if ($r <= $acc) {
                return (int)$it['index'];
            }
        }
        return (int)$items[count($items) - 1]['index'];
    }
}
