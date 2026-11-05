<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Integration\Cron;

use Ibexa\Bundle\Cron\Registry\CronJob;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Contracts\Test\Core\IbexaKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;

#[CoversClass(CronJobsRegistry::class)]
final class CronBundleTest extends IbexaKernelTestCase
{
    private CronJobsRegistry $registry;

    protected function setUp(): void
    {
        $registry = self::getIbexaTestCore()->getServiceByClassName(CronJobsRegistry::class);
        self::assertInstanceOf(CronJobsRegistry::class, $registry);
        $this->registry = $registry;
    }

    public function testRunCommandIsRegistered(): void
    {
        $application = new Application(self::$kernel);

        self::assertTrue($application->has('ibexa:cron:run'));
        self::assertSame('default', $application->find('ibexa:cron:run')->getDefinition()->getOption('category')->getDefault());
    }

    public function testTaggedServiceWithoutCategoryLandsInDefaultCategory(): void
    {
        $commandLines = $this->commandLines('default');

        self::assertCount(1, $commandLines);
        self::assertStringEndsWith(' test:cron:default  --siteaccess=default --env=test', $commandLines[0]);
    }

    public function testTaggedServiceWithCategoryAndOptions(): void
    {
        $commandLines = $this->commandLines('reports');

        self::assertCount(2, $commandLines);
        self::assertStringEndsWith(' test:cron:reports --foo=bar -v --siteaccess=default --env=test', $commandLines[0]);
        self::assertStringEndsWith(' test:cron:reports  --siteaccess=default --env=test', $commandLines[1]);
    }

    public function testUnknownCategoryHasNoJobs(): void
    {
        self::assertSame([], $this->registry->getCategoryCronJobs('missing'));
    }

    /**
     * @return list<string>
     */
    private function commandLines(string $category): array
    {
        return array_map(
            static fn (CronJob $job): string => $job->command,
            $this->registry->getCategoryCronJobs($category),
        );
    }
}
