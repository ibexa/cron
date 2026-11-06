<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cron\Registry;

use DateTimeImmutable;
use DateTimeZone;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Core\MVC\Symfony\SiteAccess;
use Ibexa\Core\MVC\Symfony\SiteAccess\SiteAccessServiceInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Pins the crontab semantics of schedules passed to CronJobsRegistry::addCronJob() (standard cron,
 * via dragonmantank/cron-expression). Keys prefixed "pitfall-N" are behaviours that used to differ
 * from standard (Vixie) cron under cron/cron and are now aligned with it.
 */
#[CoversClass(CronJobsRegistry::class)]
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

        yield 'pitfall-2 dom+dow OR, monday the 1st' => ['0 0 1 * 1', '2026-06-01 00:00', true];
        yield 'pitfall-2 dom+dow OR, monday the 8th' => ['0 0 1 * 1', '2026-06-08 00:00', true];
        yield 'pitfall-2 dom+dow OR, wednesday the 1st' => ['0 0 1 * 1', '2026-07-01 00:00', true];
        yield 'pitfall-2 dom+dow OR, tuesday the 2nd matches neither' => ['0 0 1 * 1', '2026-06-02 00:00', false];

        yield 'pitfall-3 day */2 counts from 1, matches day 1' => ['0 0 */2 * *', '2026-06-01 00:00', true];
        yield 'pitfall-3 day */2 counts from 1, matches day 3' => ['0 0 */2 * *', '2026-06-03 00:00', true];
        yield 'pitfall-3 day */2 counts from 1, skips even day 2' => ['0 0 */2 * *', '2026-06-02 00:00', false];
        yield 'pitfall-3 day */2 counts from 1, skips even day 30' => ['0 0 */2 * *', '2026-06-30 00:00', false];
        yield 'pitfall-3 month */2 counts from 1, matches january' => ['0 0 1 */2 *', '2026-01-01 00:00', true];
        yield 'pitfall-3 month */2 counts from 1, matches march' => ['0 0 1 */2 *', '2026-03-01 00:00', true];
        yield 'pitfall-3 month */2 counts from 1, skips february' => ['0 0 1 */2 *', '2026-02-01 00:00', false];
        yield 'pitfall-3 month */2 counts from 1, skips december' => ['0 0 1 */2 *', '2026-12-01 00:00', false];

        yield 'pitfall-4 dow 0 matches sunday' => ['0 0 * * 0', '2026-06-07 00:00', true];
        yield 'pitfall-4 dow 7 matches sunday' => ['0 0 * * 7', '2026-06-07 00:00', true];
        yield 'pitfall-4 dow 7 misses monday' => ['0 0 * * 7', '2026-06-08 00:00', false];
        yield 'pitfall-4 dow 6-7 matches saturday' => ['0 0 * * 6-7', '2026-06-06 00:00', true];
        yield 'pitfall-4 dow 6-7 matches sunday' => ['0 0 * * 6-7', '2026-06-07 00:00', true];
        yield 'pitfall-4 dow 6-7 misses monday' => ['0 0 * * 6-7', '2026-06-08 00:00', false];

        yield 'pitfall-6 dow name matches monday' => ['0 0 * * MON', '2026-06-08 00:00', true];
        yield 'pitfall-6 dow name misses tuesday' => ['0 0 * * MON', '2026-06-09 00:00', false];
        yield 'pitfall-6 month name matches january' => ['0 0 1 JAN *', '2026-01-01 00:00', true];
        yield 'pitfall-6 month name misses february' => ['0 0 1 JAN *', '2026-02-01 00:00', false];
        yield 'pitfall-6 @midnight matches midnight' => ['@midnight', '2026-06-03 00:00', true];
        yield 'pitfall-6 @midnight misses 00:01' => ['@midnight', '2026-06-03 00:01', false];

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

    #[DataProvider('scheduleProvider')]
    public function testIsDue(
        string $pattern,
        string $now,
        bool $expected
    ): void {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:x'), $pattern);

        self::assertSame($expected, $registry->getCategoryCronJobs('default')[0]->schedule->isDue(
            new DateTimeImmutable($now, new DateTimeZone('UTC'))
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedScheduleProvider(): iterable
    {
        yield 'pitfall-5 year field is rejected' => ['0 0 * * * 2030'];
        yield 'pitfall-5 year field is rejected, past year' => ['0 0 * * * 2020'];
        yield 'unknown shorthand' => ['@reboot'];
        yield 'minute out of range' => ['60 * * * *'];
        yield 'hour out of range' => ['0 24 * * *'];
        yield 'month 0' => ['0 0 1 0 *'];
        yield 'day 0' => ['0 0 0 * *'];
        yield 'dow 8' => ['0 0 * * 8'];
        yield 'too few fields' => ['* * * *'];
    }

    #[DataProvider('rejectedScheduleProvider')]
    public function testInvalidScheduleIsRejectedWhenJobIsAdded(string $pattern): void
    {
        $registry = $this->createRegistry();

        $this->expectException(InvalidArgumentException::class);
        $registry->addCronJob(new Command('app:foo'), $pattern);
    }

    private function createRegistry(): CronJobsRegistry
    {
        $siteAccessService = self::createStub(SiteAccessServiceInterface::class);
        $siteAccessService->method('getCurrent')->willReturn(new SiteAccess('site'));

        return new CronJobsRegistry('test', $siteAccessService);
    }
}
