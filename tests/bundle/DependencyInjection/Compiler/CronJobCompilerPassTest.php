<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cron\DependencyInjection\Compiler;

use Ibexa\Bundle\Cron\DependencyInjection\Compiler\CronJobCompilerPass;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(CronJobCompilerPass::class)]
final class CronJobCompilerPassTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setDefinition(CronJobsRegistry::class, new Definition(CronJobsRegistry::class));
    }

    public function testMissingScheduleThrows(): void
    {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['category' => 'x']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid my.job cron job configuration, schedule argument missing');

        (new CronJobCompilerPass())->process($this->container);
    }

    public function testDefaultCategoryAndEmptyOptionsAreApplied(): void
    {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => '* * * * *']));

        (new CronJobCompilerPass())->process($this->container);

        $calls = $this->container->getDefinition(CronJobsRegistry::class)->getMethodCalls();
        self::assertCount(1, $calls);
        self::assertSame('addCronJob', $calls[0][0]);
        self::assertEquals([new Reference('my.job'), '* * * * *', 'default', ''], $calls[0][1]);
    }

    public function testCategoryAndOptionsArePassedAndEveryTagIsRegistered(): void
    {
        $this->container->setDefinition('my.job', (new Definition())
            ->addTag('ibexa.cron.job', ['schedule' => '0 * * * *', 'category' => 'reports', 'options' => '--foo=bar'])
            ->addTag('ibexa.cron.job', ['schedule' => '@daily']));

        (new CronJobCompilerPass())->process($this->container);

        $calls = $this->container->getDefinition(CronJobsRegistry::class)->getMethodCalls();
        self::assertCount(2, $calls);
        self::assertEquals([new Reference('my.job'), '0 * * * *', 'reports', '--foo=bar'], $calls[0][1]);
        self::assertEquals([new Reference('my.job'), '@daily', 'default', ''], $calls[1][1]);
    }

    public function testDoesNothingWithoutRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job'));

        // The point: no exception for a tagged job without "schedule" when the registry is absent.
        $this->expectNotToPerformAssertions();

        (new CronJobCompilerPass())->process($container);
    }

    #[IgnoreDeprecations]
    #[DataProvider('provideDeprecatedSchedules')]
    public function testDeprecatedScheduleTriggersDeprecation(
        string $schedule,
        string $expectedMessage
    ): void {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => $schedule]));

        $this->expectUserDeprecationMessage(
            sprintf('Since ibexa/cron 5.0.x: Cron job "my.job" schedule "%s" %s', $schedule, $expectedMessage)
        );

        (new CronJobCompilerPass())->process($this->container);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDeprecatedSchedules(): iterable
    {
        yield 'day-of-month and day-of-week' => [
            '0 0 15 * 1',
            'restricts both day-of-month and day-of-week. They are matched with AND; since 6.0 they are matched with OR (standard cron). Restrict only one of them to keep the current behaviour.',
        ];
        yield 'step in day-of-month' => [
            '0 0 */2 * *',
            'uses a step (*/n) in day-of-month or month. Since 6.0 steps count from the first value of the field (day 1, month 1), currently from 0.',
        ];
        yield 'step in month' => [
            '0 0 1 */3 *',
            'uses a step (*/n) in day-of-month or month. Since 6.0 steps count from the first value of the field (day 1, month 1), currently from 0.',
        ];
        yield 'day-of-week 7' => [
            '0 0 * * 7',
            'uses 7 in day-of-week. Since 6.0 it means Sunday, currently it never matches.',
        ];
        yield 'day-of-week list with 7' => [
            '0 0 * * 5,7',
            'uses 7 in day-of-week. Since 6.0 it means Sunday, currently it never matches.',
        ];
        yield 'day-of-week range ending in 7' => [
            '0 0 * * 6-7',
            'uses 7 in day-of-week. Since 6.0 it means Sunday, currently it never matches.',
        ];
        yield 'descending hour range' => [
            '0 22-2 * * *',
            'uses a descending range "22-2". It currently matches every value between the two bounds; since 6.0 such a schedule is rejected. Use an ascending range or a list instead.',
        ];
        yield 'year field' => [
            '0 0 * * * 2030',
            'has a 6th (year) field. Since 6.0 it is rejected, currently it is ignored.',
        ];
    }

    #[DataProvider('provideSchedulesWithoutDeprecation')]
    public function testSupportedScheduleTriggersNothing(string $schedule): void
    {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => $schedule]));

        $deprecations = [];
        set_error_handler(static function (
            int $errno,
            string $errstr
        ) use (&$deprecations): bool {
            $deprecations[] = $errstr;

            return true;
        }, E_USER_DEPRECATED);

        try {
            (new CronJobCompilerPass())->process($this->container);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSchedulesWithoutDeprecation(): iterable
    {
        yield 'every minute' => ['* * * * *'];
        yield 'daily at 3' => ['0 3 * * *'];
        yield 'step in minute' => ['*/15 * * * *'];
        yield 'first of month' => ['0 0 1 * *'];
        yield 'mondays' => ['0 0 * * 1'];
        yield 'step in day-of-week' => ['* * * * */7'];
        yield 'ascending hour range' => ['0 2-22 * * *'];
        yield 'macro' => ['@daily'];
    }
}
