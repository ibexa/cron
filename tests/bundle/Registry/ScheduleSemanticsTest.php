<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cron\Registry;

use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Core\MVC\Symfony\SiteAccess;
use Ibexa\Tests\Cron\Support\CronLibraryAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Pins the CURRENT crontab semantics of schedules passed to CronJobsRegistry::addCronJob().
 * Keys prefixed "pitfall-N" are behaviours that differ from standard (Vixie) cron.
 */
/**
 * @covers \Ibexa\Bundle\Cron\Registry\CronJobsRegistry
 */
final class ScheduleSemanticsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function scheduleProvider(): iterable
    {
        // 2026-06-01 and 2026-06-08 are Mondays, 2026-06-07 is a Sunday, 2026-07-01 is a Wednesday.
        yield 'plain minute, match' => ['30 * * * *', '2026-06-03 12:30', true];
        yield 'plain minute, miss' => ['30 * * * *', '2026-06-03 12:31', false];
        yield 'list, match' => ['0,30 * * * *', '2026-06-03 12:30', true];
        yield 'list, miss' => ['0,30 * * * *', '2026-06-03 12:15', false];
        yield 'range, match' => ['1-5 * * * *', '2026-06-03 12:03', true];
        yield 'range, miss' => ['1-5 * * * *', '2026-06-03 12:06', false];
        yield 'step minutes, match' => ['*/15 * * * *', '2026-06-03 12:45', true];
        yield 'step minutes, miss' => ['*/15 * * * *', '2026-06-03 12:50', false];
        yield 'step hours, match' => ['0 */6 * * *', '2026-06-03 18:00', true];
        yield 'step hours, miss' => ['0 */6 * * *', '2026-06-03 13:00', false];
        yield 'specific day and month' => ['0 0 25 12 *', '2026-12-25 00:00', true];
        yield 'dow only' => ['0 0 * * 1', '2026-06-08 00:00', true];

        yield 'pitfall-2 dom+dow AND, monday the 1st' => ['0 0 1 * 1', '2026-06-01 00:00', true];
        yield 'pitfall-2 dom+dow AND, monday 8th' => ['0 0 1 * 1', '2026-06-08 00:00', false];
        yield 'pitfall-2 dom+dow AND, wednesday the 1st' => ['0 0 1 * 1', '2026-07-01 00:00', false];

        yield 'pitfall-3 day */2 matches even day' => ['0 0 */2 * *', '2026-06-02 00:00', true];
        yield 'pitfall-3 day */2 matches day 30' => ['0 0 */2 * *', '2026-06-30 00:00', true];
        yield 'pitfall-3 day */2 skips odd day 1' => ['0 0 */2 * *', '2026-06-01 00:00', false];
        yield 'pitfall-3 day */2 skips odd day 3' => ['0 0 */2 * *', '2026-06-03 00:00', false];
        yield 'pitfall-3 month */2 matches february' => ['0 0 1 */2 *', '2026-02-01 00:00', true];
        yield 'pitfall-3 month */2 matches december' => ['0 0 1 */2 *', '2026-12-01 00:00', true];
        yield 'pitfall-3 month */2 skips january' => ['0 0 1 */2 *', '2026-01-01 00:00', false];
        yield 'pitfall-3 month */2 skips march' => ['0 0 1 */2 *', '2026-03-01 00:00', false];

        yield 'pitfall-4 dow 0 matches sunday' => ['0 0 * * 0', '2026-06-07 00:00', true];
        yield 'pitfall-4 dow 7 never matches sunday' => ['0 0 * * 7', '2026-06-07 00:00', false];
        yield 'pitfall-4 dow 7 never matches monday' => ['0 0 * * 7', '2026-06-08 00:00', false];
        yield 'pitfall-4 dow 6-7 matches saturday' => ['0 0 * * 6-7', '2026-06-06 00:00', true];
        yield 'pitfall-4 dow 6-7 misses sunday' => ['0 0 * * 6-7', '2026-06-07 00:00', false];

        yield 'pitfall-5 year field ignored, past year' => ['0 0 * * * 2020', '2026-06-03 00:00', true];
        yield 'pitfall-5 year field ignored, future year' => ['0 0 * * * 2030', '2026-06-03 00:00', true];
        yield 'pitfall-5 year field ignored, minute still checked' => ['0 0 * * * 2030', '2026-06-03 00:01', false];

        yield '@daily, match' => ['@daily', '2026-06-03 00:00', true];
        yield '@daily, miss' => ['@daily', '2026-06-03 00:01', false];
        yield '@hourly, match' => ['@hourly', '2026-06-03 05:00', true];
        yield '@hourly, miss' => ['@hourly', '2026-06-03 05:01', false];
        yield '@weekly, sunday' => ['@weekly', '2026-06-07 00:00', true];
        yield '@weekly, monday' => ['@weekly', '2026-06-08 00:00', false];
        yield '@monthly, first' => ['@monthly', '2026-06-01 00:00', true];
        yield '@monthly, second' => ['@monthly', '2026-06-02 00:00', false];
        yield '@yearly, new year' => ['@yearly', '2026-01-01 00:00', true];
        yield '@yearly, other month' => ['@yearly', '2026-02-01 00:00', false];
    }

    /**
     * @dataProvider scheduleProvider
     */
    public function testIsDue(
        string $pattern,
        string $now,
        bool $expected
    ): void {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:x'), $pattern);

        self::assertSame($expected, CronLibraryAdapter::isDue(
            $registry->getCategoryCronJobs('default')[0],
            new \DateTimeImmutable($now, new \DateTimeZone('UTC'))
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedScheduleProvider(): iterable
    {
        yield 'pitfall-6 month name' => ['0 0 1 JAN *'];
        yield 'pitfall-6 day of week name' => ['0 0 * * MON'];
        yield 'unknown shorthand' => ['@reboot'];
        yield 'unknown shorthand midnight' => ['@midnight'];
        yield 'minute out of range' => ['60 * * * *'];
        yield 'hour out of range' => ['0 24 * * *'];
        yield 'month 0' => ['0 0 1 0 *'];
        yield 'day 0' => ['0 0 0 * *'];
        yield 'dow 8' => ['0 0 * * 8'];
        yield 'too few fields' => ['* * * *'];
    }

    /**
     * @dataProvider rejectedScheduleProvider
     */
    public function testInvalidScheduleIsRejectedWhenJobIsAdded(string $pattern): void
    {
        $registry = $this->createRegistry();

        // Library-agnostic: cron/cron throws InvalidPatternException, dragonmantank \InvalidArgumentException.
        $this->expectException(\Exception::class);
        $registry->addCronJob(new Command('app:foo'), $pattern);
    }

    private function createRegistry(): CronJobsRegistry
    {
        return new CronJobsRegistry('test', new SiteAccess('site'));
    }
}
