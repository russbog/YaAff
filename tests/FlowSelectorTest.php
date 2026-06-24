<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../flow/FlowSelector.php';

class FlowSelectorTest extends TestCase
{
    private function rng(int $value): callable
    {
        return fn(int $min, int $max): int => $value;
    }

    public function testForcedWinsOverRegular(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => true, 'type' => 'regular', 'weight' => 10],
            ['index' => 1, 'matches' => true, 'type' => 'forced', 'weight' => 1],
        ];
        $this->assertSame(1, FlowSelector::select($candidates, $this->rng(1)));
    }

    public function testFirstMatchingForcedWins(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => false, 'type' => 'forced', 'weight' => 1],
            ['index' => 1, 'matches' => true, 'type' => 'forced', 'weight' => 1],
            ['index' => 2, 'matches' => true, 'type' => 'forced', 'weight' => 1],
        ];
        $this->assertSame(1, FlowSelector::select($candidates, $this->rng(1)));
    }

    public function testRegularUsedWhenNoForcedMatches(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => true, 'type' => 'regular', 'weight' => 1],
            ['index' => 1, 'matches' => true, 'type' => 'default', 'weight' => 1],
        ];
        $this->assertSame(0, FlowSelector::select($candidates, $this->rng(1)));
    }

    public function testWeightedSplitHonoursWeights(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => true, 'type' => 'regular', 'weight' => 3],
            ['index' => 1, 'matches' => true, 'type' => 'regular', 'weight' => 7],
        ];
        // r in 1..3 => index 0, r in 4..10 => index 1
        $this->assertSame(0, FlowSelector::select($candidates, $this->rng(3)));
        $this->assertSame(1, FlowSelector::select($candidates, $this->rng(4)));
    }

    public function testDefaultIsFallbackWhenNoRegularMatched(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => false, 'type' => 'regular', 'weight' => 1],
            ['index' => 1, 'matches' => true, 'type' => 'default', 'weight' => 1],
        ];
        $this->assertSame(1, FlowSelector::select($candidates, $this->rng(1)));
    }

    public function testRegularBeatsDefault(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => true, 'type' => 'default', 'weight' => 1],
            ['index' => 1, 'matches' => true, 'type' => 'regular', 'weight' => 1],
        ];
        $this->assertSame(1, FlowSelector::select($candidates, $this->rng(1)));
    }

    public function testNothingMatchesReturnsNull(): void
    {
        $candidates = [
            ['index' => 0, 'matches' => false, 'type' => 'regular', 'weight' => 1],
            ['index' => 1, 'matches' => false, 'type' => 'default', 'weight' => 1],
        ];
        $this->assertNull(FlowSelector::select($candidates, $this->rng(1)));
        $this->assertNull(FlowSelector::select([], $this->rng(1)));
    }

    public function testZeroWeightsFallBackToUniformPick(): void
    {
        $candidates = [
            ['index' => 5, 'matches' => true, 'type' => 'regular', 'weight' => 0],
            ['index' => 6, 'matches' => true, 'type' => 'regular', 'weight' => 0],
        ];
        $this->assertSame(6, FlowSelector::select($candidates, $this->rng(1)));
    }
}
