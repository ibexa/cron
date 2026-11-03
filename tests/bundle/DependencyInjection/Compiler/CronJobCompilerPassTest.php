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
}
