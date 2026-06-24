<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../rules/RuleEvaluator.php';

class RuleEvaluatorTest extends TestCase
{
    public function testNoConditionsAlwaysMatches(): void
    {
        $this->assertTrue(RuleEvaluator::evaluate(['roi' => -50], []));
    }

    public function testMatchAllRequiresEveryCondition(): void
    {
        $metrics = ['roi' => -30, 'clicks' => 500];
        $conds = [
            ['metric' => 'roi', 'op' => 'lt', 'value' => -20],
            ['metric' => 'clicks', 'op' => 'gte', 'value' => 100],
        ];
        $this->assertTrue(RuleEvaluator::evaluate($metrics, $conds, 'all'));

        $conds[1]['value'] = 1000; // clicks 500 < 1000 fails
        $this->assertFalse(RuleEvaluator::evaluate($metrics, $conds, 'all'));
    }

    public function testMatchAnyRequiresOneCondition(): void
    {
        $metrics = ['roi' => 10, 'clicks' => 5];
        $conds = [
            ['metric' => 'roi', 'op' => 'lt', 'value' => -20],
            ['metric' => 'clicks', 'op' => 'lt', 'value' => 10],
        ];
        $this->assertTrue(RuleEvaluator::evaluate($metrics, $conds, 'any'));

        $conds[1]['value'] = 1; // clicks 5 < 1 fails too
        $this->assertFalse(RuleEvaluator::evaluate($metrics, $conds, 'any'));
    }

    public function testMissingMetricTreatedAsZero(): void
    {
        $this->assertTrue(RuleEvaluator::evaluate([], [['metric' => 'profit', 'op' => 'eq', 'value' => 0]]));
        $this->assertTrue(RuleEvaluator::evaluate([], [['metric' => 'cr', 'op' => 'lt', 'value' => 1]]));
    }

    public function testOperatorAliasesAndComparisons(): void
    {
        $this->assertTrue(RuleEvaluator::compare(5, '>', 3));
        $this->assertTrue(RuleEvaluator::compare(5, 'gt', 3));
        $this->assertTrue(RuleEvaluator::compare(3, '>=', 3));
        $this->assertTrue(RuleEvaluator::compare(2, 'lte', 2));
        $this->assertTrue(RuleEvaluator::compare(4, '!=', 5));
        $this->assertFalse(RuleEvaluator::compare(4, 'bogus', 5));
    }
}
