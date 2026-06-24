<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../rules/Schedule.php';

class ScheduleTest extends TestCase
{
    public function testEmptyScheduleAlwaysDue(): void
    {
        $s = new Schedule('');
        $this->assertTrue($s->isDue(1000, 999));
        $this->assertTrue($s->isDue(1000, null));
    }

    public function testIntervalRespectsElapsedTime(): void
    {
        $s = new Schedule('300');
        $this->assertTrue($s->isDue(1000, null), 'never run -> due');
        $this->assertFalse($s->isDue(1200, 1000), '200s < 300s -> not due');
        $this->assertTrue($s->isDue(1300, 1000), '300s elapsed -> due');
    }

    public function testIntervalShorthandUnits(): void
    {
        $this->assertTrue((new Schedule('every:5m'))->isDue(0 + 300, 0));
        $this->assertFalse((new Schedule('every:5m'))->isDue(0 + 299, 0));
        $this->assertTrue((new Schedule('every:2h'))->isDue(7200, 0));
        $this->assertTrue((new Schedule('every:1d'))->isDue(86400, 0));
    }

    public function testCronHourlyMatchesTopOfHour(): void
    {
        $s = new Schedule('0 * * * *', 'UTC');
        $topOfHour = strtotime('2024-01-01 15:00:30 UTC');
        $quarterPast = strtotime('2024-01-01 15:15:00 UTC');
        $this->assertTrue($s->isDue($topOfHour, null));
        $this->assertFalse($s->isDue($quarterPast, null));
    }

    public function testCronDailyMacro(): void
    {
        $s = new Schedule('@daily', 'UTC');
        $midnight = strtotime('2024-03-10 00:00:05 UTC');
        $noon = strtotime('2024-03-10 12:00:00 UTC');
        $this->assertTrue($s->isDue($midnight, null));
        $this->assertFalse($s->isDue($noon, null));
    }

    public function testCronStepAndListFields(): void
    {
        $s = new Schedule('*/15 * * * *', 'UTC');
        $this->assertTrue($s->isDue(strtotime('2024-01-01 10:30:00 UTC'), null));
        $this->assertFalse($s->isDue(strtotime('2024-01-01 10:31:00 UTC'), null));

        $dow = new Schedule('0 0 * * 1,3', 'UTC'); // Mon & Wed
        $this->assertTrue($dow->isDue(strtotime('2024-01-01 00:00:00 UTC'), null), 'Mon');
        $this->assertFalse($dow->isDue(strtotime('2024-01-02 00:00:00 UTC'), null), 'Tue');
        $this->assertTrue($dow->isDue(strtotime('2024-01-03 00:00:00 UTC'), null), 'Wed');
    }

    public function testCronDoesNotFireTwiceInSameMinute(): void
    {
        $s = new Schedule('0 * * * *', 'UTC');
        $now = strtotime('2024-01-01 15:00:00 UTC');
        $this->assertTrue($s->isDue($now, null));
        $this->assertFalse($s->isDue($now + 30, $now), 'already ran this minute');
        $next = strtotime('2024-01-01 16:00:00 UTC');
        $this->assertTrue($s->isDue($next, $now), 'next hour -> due again');
    }

    public function testCronRespectsTimezone(): void
    {
        $s = new Schedule('0 9 * * *', 'America/New_York'); // 09:00 NY
        // 2024-01-01 09:00 NY == 14:00 UTC
        $this->assertTrue($s->isDue(strtotime('2024-01-01 14:00:00 UTC'), null));
        $this->assertFalse($s->isDue(strtotime('2024-01-01 09:00:00 UTC'), null));
    }
}
