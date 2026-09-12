<?php
/**
 * rcloneCWP Cron Parser & Next-Run Calculator
 *
 * Parses standard 5-field cron expressions and calculates precise next run
 * datetimes with timezone support. PHP 7.1+ compatible.
 * @package CWP\RcloneCWP\Scheduling
 */

namespace CWP\RcloneCWP\Scheduling;

class CronParser
{
    /** @var string|null The original cron expression */
    private $expr;

    /** @var array Month mapping (1-12 => name) */
    private static $monthMap = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    /** @var array Day-of-week mapping (0-7 => name, 0 and 7 both Sunday) */
    private static $dayMap = [
        0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed',
        4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
    ];

    /**
     * Common preset expressions for UI convenience.
     */
    public static function presets(): array
    {
        return [
            'hourly'      => '0 * * * *',
            'twicedaily'  => '0 2,14 * * *',
            'daily'       => '0 2 * * *',
            'weekly'      => '0 2 * * 0',
            'monthly'     => '0 2 1 * *',
            'yearly'      => '0 0 1 1 *',
            'every_5min'  => '*/5 * * * *',
            'every_10min' => '*/10 * * * *',
            'every_15min' => '*/15 * * * *',
            'every_30min' => '0,30 * * * *',
        ];
    }

    /**
     * Validate a 5-field cron expression.
     *
     * @param string|null $expr
     * @return bool
     */
    public static function isValid(string $expr = null): bool
    {
        if ($expr === null || trim($expr) === '') {
            return false;
        }

        $parts = preg_split('/\s+/', trim($expr));
        if (count($parts) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59], // minute
            [0, 23], // hour
            [1, 31], // day of month
            [1, 12], // month
            [0, 7],  // day of week (0 and 7 both Sunday)
        ];

        foreach ($parts as $i => $field) {
            if (!self::validateField($field, $ranges[$i][0], $ranges[$i][1])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate an individual cron field token against its bounds.
     *
     * @param string $field
     * @param int    $min
     * @param int    $max
     * @return bool
     */
    private static function validateField(string $field, int $min, int $max): bool
    {
        if ($field === '') {
            return false;
        }

        $items = explode(',', $field);
        foreach ($items as $item) {
            if ($item === '') {
                return false;
            }

            if (strpos($item, '/') !== false) {
                $slashParts = explode('/', $item);
                if (count($slashParts) !== 2) {
                    return false;
                }
                $base = $slashParts[0];
                $stepStr = $slashParts[1];
                if (!ctype_digit($stepStr)) {
                    return false;
                }
                $step = (int) $stepStr;
                if ($step < 1 || $step > $max) {
                    return false;
                }
            } else {
                $base = $item;
            }

            if ($base === '*') {
                continue;
            }

            if (strpos($base, '-') !== false) {
                $dashParts = explode('-', $base);
                if (count($dashParts) !== 2) {
                    return false;
                }
                if (!ctype_digit($dashParts[0]) || !ctype_digit($dashParts[1])) {
                    return false;
                }
                $start = (int) $dashParts[0];
                $end   = (int) $dashParts[1];
                if ($start < $min || $end > $max || $start > $end) {
                    return false;
                }
            } else {
                if (!ctype_digit($base)) {
                    return false;
                }
                $val = (int) $base;
                if ($val < $min || $val > $max) {
                    return false;
                }
            }
        }

        return true;
    }

    // --------------------------------------------------------------------------
    // Field expansion
    // --------------------------------------------------------------------------

    /**
     * Expand a single cron field into an array of integers.
     *
     * @param string $field  The cron field token (e.g. "step values, 1,15, 10-20/2, *")
     * @param int    $min    Minimum allowed value for this field
     * @param int    $max    Maximum allowed value for this field
     * @return array Sorted unique integers that satisfy this field
     */
    private function expandField(string $field, int $min, int $max): array
    {
        if ($field === '*') {
            return range($min, $max);
        }

        $values = [];
        $parts = explode(',', $field);

        foreach ($parts as $part) {
            $step = 1;
            if (strpos($part, '/') !== false) {
                [$base, $step] = explode('/', $part, 2);
                $step = (int) $step;
                if ($step < 1) {
                    $step = 1;
                }
            } else {
                $base = $part;
            }

            if ($base === '*') {
                $start = $min;
                $end   = $max;
            } elseif (strpos($base, '-') !== false) {
                [$start, $end] = explode('-', $base, 2);
                $start = (int) $start;
                $end   = (int) $end;
                if ($end < $min) {
                    $end = $min;
                }
                if ($start < $min) {
                    $start = $min;
                }
                if ($end > $max) {
                    $end = $max;
                }
                if ($start > $end) {
                    $start = $min;
                    $end   = $max;
                }
            } else {
                $start = $end = (int) $base;
                if ($start < $min) {
                    $start = $min;
                }
                if ($end > $max) {
                    $end = $max;
                }
            }

            for ($v = $start; $v <= $end; $v += $step) {
                $values[$v] = true;
            }
        }

        $result = array_keys($values);
        sort($result);
        return $result;
    }

    // --------------------------------------------------------------------------
    // Next-run calculation
    // --------------------------------------------------------------------------

    /**
     * Parse a cron expression into field-value arrays.
     *
     * @param string $expr
     * @return array{minutes:array, hours:array, dom:array, months:array, dow:array}
     */
    private function parse(string $expr): array
    {
        $parts = preg_split('/\s+/', trim($expr));

        return [
            'minutes'  => $this->expandField($parts[0], 0, 59),
            'hours'    => $this->expandField($parts[1], 0, 23),
            'dom'      => $this->expandField($parts[2], 1, 31),
            'months'   => $this->expandField($parts[3], 1, 12),
            'dow'      => $this->expandField($parts[4], 0, 7),
        ];
    }

    /**
     * Calculate the next run datetime after the given start time.
     *
     * @param string $expr     Valid 5-field cron expression
     * @param int|null $start  Unix timestamp (defaults to now)
     * @param \DateTimeZone|string|null $tz Timezone (defaults to UTC)
     * @return \DateTime The next run datetime (in the given timezone)
     */
    public static function nextRun(
        string $expr,
        int $start = null,
        $tz = null
    ): \DateTime {
        if (!self::isValid($expr)) {
            throw new \InvalidArgumentException('Invalid cron expression: ' . $expr);
        }

        $start = $start ?: time();

        $timezone = self::resolveTimezone($tz);
        $dt = new \DateTime('now', $timezone);
        $dt->setTimestamp($start);

        // Start from the next minute to find a future run
        $dt->modify('+1 minute');
        $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

        $parser = new self();
        $fields = $parser->parse($expr);

        // Normalize DOW: convert 7 to 0
        $fields['dow'] = array_map(function ($d) {
            return $d === 7 ? 0 : $d;
        }, $fields['dow']);

        // Iterate day by day, up to 366 days
        for ($day = 0; $day < 366; $day++) {
            $month  = (int) $dt->format('n');
            $dom    = (int) $dt->format('j');
            $dow    = (int) $dt->format('w');

            if (in_array($month, $fields['months'], true)
                && in_array($dom, $fields['dom'], true)
                && in_array($dow, $fields['dow'], true)
            ) {
                // Found a matching day, now search hours then minutes
                $hour = (int) $dt->format('G');
                $minute = (int) $dt->format('i');

                // Try minutes in this exact hour
                $found = false;
                foreach ($fields['hours'] as $h) {
                    if ($h < $hour) {
                        continue;
                    }
                    if ($h > $hour) {
                        // Reset to start of new hour, find first valid minute
                        $targetMin = $fields['minutes'][0];
                        if ($found) {
                            break;
                        }
                        $result = clone $dt;
                        $result->setTime((int) $h, (int) $targetMin, 0);
                        return $result;
                    }
                    // $h == $hour — check remaining minutes >= current minute
                    foreach ($fields['minutes'] as $m) {
                        if ($m >= $minute) {
                            $result = clone $dt;
                            $result->setTime((int) $h, (int) $m, 0);
                            return $result;
                        }
                    }
                    $found = true;
                }

                // Need to roll to next hour(s)
                if ($found) {
                    // We exhausted minutes in the current hour, try next hour in same day
                    $nextHourIdx = null;
                    foreach ($fields['hours'] as $idx => $h) {
                        if ($h > $hour) {
                            $nextHourIdx = $idx;
                            break;
                        }
                    }
                    if ($nextHourIdx !== null) {
                        $result = clone $dt;
                        $result->setTime((int) $fields['hours'][$nextHourIdx], (int) $fields['minutes'][0], 0);
                        return $result;
                    }
                }
            }

            // Move to next day at 00:00
            $dt->modify('+1 day');
            $dt->setTime(0, 0, 0);
        }

        // Fallback: 1 year ahead
        $dt->modify('+1 year');
        $dt->setTime(0, 0, 0);
        return $dt;
    }

    /**
     * Calculate the previous run datetime before the given end time.
     *
     * @param string $expr
     * @param int|null $end Unix timestamp (defaults to now)
     * @param \DateTimeZone|string|null $tz
     * @return \DateTime|null Null if no previous run in the past
     */
    public static function prevRun(
        string $expr,
        int $end = null,
        $tz = null
    ): ?\DateTime {
        if (!self::isValid($expr)) {
            return null;
        }

        $end = $end ?: time();
        $timezone = self::resolveTimezone($tz);

        $dt = new \DateTime('now', $timezone);
        $dt->setTimestamp($end);
        $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0);

        $parser = new self();
        $fields = $parser->parse($expr);

        $fields['dow'] = array_map(function ($d) {
            return $d === 7 ? 0 : $d;
        }, $fields['dow']);

        for ($day = 0; $day < 366; $day++) {
            $month  = (int) $dt->format('n');
            $dom    = (int) $dt->format('j');
            $dow    = (int) $dt->format('w');
            $hour   = (int) $dt->format('G');
            $minute = (int) $dt->format('i');

            if (in_array($month, $fields['months'], true)
                && in_array($dom, $fields['dom'], true)
                && in_array($dow, $fields['dow'], true)
            ) {
                // Search backwards through hours and minutes
                // Try hours less than current hour, last valid minute
                for ($hi = count($fields['hours']) - 1; $hi >= 0; $hi--) {
                    $h = $fields['hours'][$hi];
                    if ($h > $hour) {
                        continue;
                    }

                    // Find last valid minute
                    $maxMinute = -1;
                    foreach ($fields['minutes'] as $m) {
                        if ($h < $hour || $m <= $minute) {
                            $maxMinute = $m;
                        }
                    }
                    if ($maxMinute >= 0) {
                        $result = clone $dt;
                        $result->setTime((int) $h, (int) $maxMinute, 0);
                        return $result;
                    }
                }

                // Try previous day(s)
            }

            $dt->modify('-1 day');
            $dt->setTime(23, 59, 0);
        }

        return null;
    }

    /**
     * Get next N run times for a cron expression (for UI preview).
     *
     * @param string $expr
     * @param int   $count How many runs to return
     * @param \DateTimeZone|string|null $tz
     * @return \DateTime[]
     */
    public static function nextRuns(
        string $expr,
        int $count = 5,
        $tz = null
    ): array {
        $runs = [];
        $base = time();

        for ($i = 0; $i < $count; $i++) {
            $dt = self::nextRun($expr, $base, $tz);
            $runs[] = $dt;
            $base = $dt->getTimestamp();
        }

        return $runs;
    }

    // --------------------------------------------------------------------------
    // Timezone resolution
    // --------------------------------------------------------------------------

    /**
     * Resolve a timezone value to a DateTimeZone instance.
     *
     * @param \DateTimeZone|string|null $tz
     * @return \DateTimeZone
     */
    private static function resolveTimezone($tz): \DateTimeZone
    {
        if ($tz instanceof \DateTimeZone) {
            return $tz;
        }
        if (is_string($tz) && $tz !== '') {
            try {
                return new \DateTimeZone($tz);
            } catch (\Exception $e) {
                // Fall through to UTC
            }
        }
        return new \DateTimeZone('UTC');
    }
}
