<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cron\Registry;

use Cron\CronExpression;
use InvalidArgumentException;

/**
 * @internal
 */
final class ScheduleValidator
{
    /** Month and day-of-week names as dragonmantank/cron-expression numbers them (SUN is 7). */
    private const array RANGE_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6, 'SUN' => 7,
    ];

    /**
     * @throws InvalidArgumentException with the reason the schedule is invalid
     */
    public function validate(string $schedule): void
    {
        // dragonmantank/cron-expression accepts descending ranges (e.g. "22-2", "FRI-MON") but never matches them
        // (hour/minute ones also flood PHP warnings on every check), so reject them early.
        preg_match_all('/([a-z\d]+)-([a-z\d]+)/i', $schedule, $ranges, PREG_SET_ORDER);
        foreach ($ranges as [$range, $start, $end]) {
            if ($this->rangeBound($start) > $this->rangeBound($end)) {
                throw new InvalidArgumentException(sprintf(
                    'descending range "%s" is not supported. Use an ascending range or a list instead.',
                    $range
                ));
            }
        }

        if (!CronExpression::isValidExpression($schedule)) {
            throw new InvalidArgumentException('not a valid cron expression');
        }
    }

    /**
     * Unknown names map to 0, isValidExpression() rejects them.
     */
    private function rangeBound(string $bound): int
    {
        return ctype_digit($bound) ? (int) $bound : self::RANGE_NAMES[strtoupper($bound)] ?? 0;
    }
}
