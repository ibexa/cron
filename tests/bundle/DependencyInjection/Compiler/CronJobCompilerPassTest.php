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
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
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

    #[DataProvider('provideInvalidSchedules')]
    public function testInvalidScheduleThrows(
        string $schedule,
        string $reason
    ): void {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => $schedule]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Invalid my.job cron job schedule "%s", %s', $schedule, $reason));

        (new CronJobCompilerPass())->process($this->container);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidSchedules(): iterable
    {
        yield 'year field' => ['0 0 * * * 2030', 'expected 5 fields (minute, hour, day of month, month, day of week), got 6'];
        yield 'too few fields' => ['0 0 * *', 'expected 5 fields (minute, hour, day of month, month, day of week), got 4'];
        yield 'invalid hour' => ['0 25 * * *', 'invalid hour field "25"'];
        yield 'invalid day of week' => ['0 0 * * FOO', 'invalid day of week field "FOO"'];
        yield 'unknown shortcut' => ['@sometimes', 'not a valid cron expression'];
    }

    #[DataProvider('provideDescendingRanges')]
    public function testDescendingRangeThrows(
        string $schedule,
        string $range
    ): void {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => $schedule]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Invalid my.job cron job schedule "%s", descending range "%s" is not supported.', $schedule, $range));

        (new CronJobCompilerPass())->process($this->container);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDescendingRanges(): iterable
    {
        yield 'hours' => ['0 22-2 * * *', '22-2'];
        yield 'day-of-week names' => ['0 0 * * FRI-MON', 'FRI-MON'];
        yield 'lowercase names' => ['0 0 * * sun-sat', 'sun-sat'];
        yield 'month names' => ['0 0 1 NOV-JAN *', 'NOV-JAN'];
    }

    #[DataProvider('provideAscendingRanges')]
    public function testAscendingRangeIsRegistered(string $schedule): void
    {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => $schedule]));

        (new CronJobCompilerPass())->process($this->container);

        self::assertCount(1, $this->container->getDefinition(CronJobsRegistry::class)->getMethodCalls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAscendingRanges(): iterable
    {
        yield 'numbers' => ['0 2-22 * * 1-5'];
        yield 'single-value range' => ['0 5-5 * * *'];
        yield 'day-of-week names' => ['0 0 * * MON-FRI'];
        yield 'month names' => ['0 0 1 JAN-MAR *'];
    }

    public function testParameterInScheduleIsResolvedForValidation(): void
    {
        $this->container->setParameter('app.cron_schedule', '0 22-2 * * *');
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => '%app.cron_schedule%']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid my.job cron job schedule "0 22-2 * * *", descending range "22-2" is not supported.');

        (new CronJobCompilerPass())->process($this->container);
    }

    public function testParameterInScheduleIsPassedUnresolved(): void
    {
        $this->container->setParameter('app.cron_schedule', '0 3 * * *');
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => '%app.cron_schedule%']));

        (new CronJobCompilerPass())->process($this->container);

        $calls = $this->container->getDefinition(CronJobsRegistry::class)->getMethodCalls();
        self::assertSame('%app.cron_schedule%', $calls[0][1][1]);
    }

    public function testEnvScheduleIsLeftForRuntime(): void
    {
        $this->container->setDefinition('my.job', (new Definition())->addTag('ibexa.cron.job', ['schedule' => '%env(APP_CRON_SCHEDULE)%']));

        (new CronJobCompilerPass())->process($this->container);

        $calls = $this->container->getDefinition(CronJobsRegistry::class)->getMethodCalls();
        self::assertSame('%env(APP_CRON_SCHEDULE)%', $calls[0][1][1]);
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
}
