<?php
/**
 * Unit tests for CronParser class
 */
namespace CWP\RcloneCWP\Tests\Unit;

use CWP\RcloneCWP\Scheduling\CronParser;
use PHPUnit\Framework\TestCase;

class CronParserTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isValid()
    // -------------------------------------------------------------------------

    public function testIsValidWithNull()
    {
        $this->assertFalse(CronParser::isValid(null));
    }

    public function testIsValidWithEmptyString()
    {
        $this->assertFalse(CronParser::isValid(''));
    }

    public function testIsValidWithWhitespaceOnly()
    {
        $this->assertFalse(CronParser::isValid('   '));
    }

    public function testIsValidWithTooFewFields()
    {
        $this->assertFalse(CronParser::isValid('* * *'));
    }

    public function testIsValidWithTooManyFields()
    {
        $this->assertFalse(CronParser::isValid('* * * * * *'));
    }

    /**
     * @dataProvider validCronProvider
     */
    public function testIsValidAcceptsValidExpressions(string $expr)
    {
        $this->assertTrue(CronParser::isValid($expr), "Expected valid: $expr");
    }

    public function validCronProvider(): array
    {
        return [
            'every minute'       => ['* * * * *'],
            'daily at 2am'       => ['0 2 * * *'],
            'hourly'             => ['0 * * * *'],
            'weekly Sunday'      => ['0 2 * * 0'],
            'monthly 1st'        => ['0 2 1 * *'],
            'every 5 minutes'    => ['*/5 * * * *'],
            'comma list'         => ['0 2,14 * * *'],
            'range'              => ['0 9-17 * * *'],
            'range with step'    => ['0 9-17/2 * * *'],
            'last DOW (7=Sun)'   => ['0 0 * * 7'],
            'min max values'     => ['59 23 31 12 6'],
            'dow names via nums' => ['0 0 * * 1,3,5'],
        ];
    }

    /**
     * @dataProvider invalidCronProvider
     */
    public function testIsValidRejectsInvalidExpressions(string $expr)
    {
        $this->assertFalse(CronParser::isValid($expr), "Expected invalid: $expr");
    }

    public function invalidCronProvider(): array
    {
        return [
            'minute out of range'    => ['60 * * * *'],
            'hour out of range'      => ['0 24 * * *'],
            'dom out of range'       => ['0 0 32 * *'],
            'month out of range'     => ['0 0 * 13 *'],
            'dow out of range'       => ['0 0 * * 8'],
            'negative minute'        => ['-1 * * * *'],
            'empty field'            => ['* * * * '],
            'double slash'           => ['*/* * * * *'],
            'invalid range start'    => ['60-0 * * * *'],
            'non-numeric step'       => ['*/x * * * *'],
            'step zero'              => ['*/0 * * * *'],
            'single field'           => ['0'],
            'letters in minute'      => ['abc * * * *'],
            'double comma'           => ['0,,1 * * * *'],
        ];
    }

    // -------------------------------------------------------------------------
    // presets()
    // -------------------------------------------------------------------------

    public function testPresetsAreAllValid()
    {
        foreach (CronParser::presets() as $name => $expr) {
            $this->assertTrue(CronParser::isValid($expr), "Preset '$name' ($expr) should be valid");
        }
    }

    public function testPresetsContainsExpectedKeys()
    {
        $presets = CronParser::presets();
        $this->assertArrayHasKey('daily', $presets);
        $this->assertArrayHasKey('hourly', $presets);
        $this->assertArrayHasKey('weekly', $presets);
        $this->assertArrayHasKey('monthly', $presets);
    }

    // -------------------------------------------------------------------------
    // nextRun()
    // -------------------------------------------------------------------------

    public function testNextRunThrowsOnInvalidExpr()
    {
        $this->expectException(\InvalidArgumentException::class);
        CronParser::nextRun('invalid');
    }

    public function testNextRunDailyAt2am()
    {
        // 2024-06-15 10:00 UTC => next run 2024-06-16 02:00 UTC
        $start = gmmktime(10, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 2 * * *', $start, 'UTC');
        $this->assertEquals('2024-06-16 02:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunHourly()
    {
        // 2024-06-15 10:30 UTC => next run 2024-06-15 11:00 UTC
        $start = gmmktime(10, 30, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 * * * *', $start, 'UTC');
        $this->assertEquals('2024-06-15 11:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunEvery5Minutes()
    {
        // 2024-06-15 10:07 UTC => next run 2024-06-15 10:10 UTC
        $start = gmmktime(10, 7, 0, 6, 15, 2024);
        $next = CronParser::nextRun('*/5 * * * *', $start, 'UTC');
        $this->assertEquals('2024-06-15 10:10', $next->format('Y-m-d H:i'));
    }

    public function testNextRunWeeklySunday()
    {
        // 2024-06-15 is Saturday. Next Sunday is 2024-06-16
        $start = gmmktime(10, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 2 * * 0', $start, 'UTC');
        $this->assertEquals('2024-06-16 02:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunMonthlyFirst()
    {
        // 2024-06-15 => next 1st of month is 2024-07-01
        $start = gmmktime(10, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 2 1 * *', $start, 'UTC');
        $this->assertEquals('2024-07-01 02:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunWithCommaList()
    {
        // 2024-06-15 03:00 UTC, runs at 2 and 14 => next is 14:00
        $start = gmmktime(3, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 2,14 * * *', $start, 'UTC');
        $this->assertEquals('2024-06-15 14:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunWithRange()
    {
        // 2024-06-15 08:00 UTC, runs 9-17 => next is 09:00
        $start = gmmktime(8, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 9-17 * * *', $start, 'UTC');
        $this->assertEquals('2024-06-15 09:00', $next->format('Y-m-d H:i'));
    }

    public function testNextRunIsAlwaysInFuture()
    {
        // Verify nextRun strictly after start
        $start = gmmktime(10, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('*/5 * * * *', $start, 'UTC');
        $this->assertGreaterThan($start, $next->getTimestamp());
    }

    public function testNextRunWithTimezone()
    {
        // Start is midnight UTC on 2024-06-15
        // Cron "0 2 * * *" in Eastern => next 2am Eastern on 2024-06-15
        // DateTime is returned in Eastern timezone, so format gives 02:00 EDT
        $start = gmmktime(0, 0, 0, 6, 15, 2024);
        $next = CronParser::nextRun('0 2 * * *', $start, 'US/Eastern');
        $this->assertEquals('02:00', $next->format('H:i'));
        $this->assertEquals('2024-06-15', $next->format('Y-m-d'));
        // Verify the UTC equivalent is correct (06:00 UTC)
        $utcClone = clone $next;
        $utcClone->setTimezone(new \DateTimeZone('UTC'));
        $this->assertEquals('06:00', $utcClone->format('H:i'));
    }

    // -------------------------------------------------------------------------
    // nextRuns()
    // -------------------------------------------------------------------------

    public function testNextRunsReturnsCorrectCount()
    {
        $runs = CronParser::nextRuns('0 * * * *', 5, 'UTC');
        $this->assertCount(5, $runs);
    }

    public function testNextRunsAreStrictlyIncreasing()
    {
        $runs = CronParser::nextRuns('0 * * * *', 5, 'UTC');
        for ($i = 1; $i < count($runs); $i++) {
            $this->assertGreaterThan(
                $runs[$i - 1]->getTimestamp(),
                $runs[$i]->getTimestamp(),
                "Run $i should be after run " . ($i - 1)
            );
        }
    }

    public function testNextRunsDailySpacing()
    {
        $runs = CronParser::nextRuns('0 2 * * *', 3, 'UTC');
        // Each run should be ~86400 seconds apart (daily)
        for ($i = 1; $i < count($runs); $i++) {
            $diff = $runs[$i]->getTimestamp() - $runs[$i - 1]->getTimestamp();
            $this->assertEquals(86400, $diff);
        }
    }

    // -------------------------------------------------------------------------
    // prevRun()
    // -------------------------------------------------------------------------

    public function testPrevRunReturnsNullForInvalidExpr()
    {
        $this->assertNull(CronParser::prevRun('invalid'));
    }

    public function testPrevRunDailyBefore()
    {
        // 2024-06-15 10:00 UTC => prev "0 2 * * *" is 2024-06-15 02:00
        $end = gmmktime(10, 0, 0, 6, 15, 2024);
        $prev = CronParser::prevRun('0 2 * * *', $end, 'UTC');
        $this->assertEquals('2024-06-15 02:00', $prev->format('Y-m-d H:i'));
    }

    public function testPrevRunIsStrictlyBefore()
    {
        $end = gmmktime(10, 0, 0, 6, 15, 2024);
        $prev = CronParser::prevRun('*/5 * * * *', $end, 'UTC');
        $this->assertLessThan($end, $prev->getTimestamp());
    }
}
