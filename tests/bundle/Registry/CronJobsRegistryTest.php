<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cron\Registry;

use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Core\MVC\Symfony\SiteAccess;
use Ibexa\Core\MVC\Symfony\SiteAccess\SiteAccessServiceInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\PhpExecutableFinder;

#[CoversClass(CronJobsRegistry::class)]
final class CronJobsRegistryTest extends TestCase
{
    private ?string $scriptName;

    protected function setUp(): void
    {
        $this->scriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = 'bin/console';
    }

    protected function tearDown(): void
    {
        if ($this->scriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->scriptName;
        }
    }

    public function testCommandLineFormat(): void
    {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:foo'), '* * * * *', 'default', '--bar=1 -v');

        $php = (new PhpExecutableFinder())->find();
        self::assertSame(
            $php . ' bin/console app:foo --bar=1 -v --siteaccess=site --env=prod',
            $registry->getCategoryCronJobs('default')[0]->command
        );
    }

    public function testEmptyOptionsLeaveDoubleSpace(): void
    {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:foo'), '* * * * *');

        $php = (new PhpExecutableFinder())->find();
        self::assertSame(
            $php . ' bin/console app:foo  --siteaccess=site --env=prod',
            $registry->getCategoryCronJobs(CronJobsRegistry::DEFAULT_CATEGORY)[0]->command
        );
    }

    public function testJobsAreBucketedByCategory(): void
    {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:a'), '* * * * *');
        $registry->addCronJob(new Command('app:b'), '* * * * *', 'reports');
        $registry->addCronJob(new Command('app:c'), '* * * * *', 'reports');

        self::assertCount(1, $registry->getCategoryCronJobs('default'));
        self::assertCount(2, $registry->getCategoryCronJobs('reports'));
    }

    public function testUnknownCategoryReturnsEmptyArray(): void
    {
        $registry = $this->createRegistry();
        $registry->addCronJob(new Command('app:a'), '* * * * *');

        self::assertSame([], $registry->getCategoryCronJobs('nope'));
    }

    public function testCommandWithoutNameThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unable to get a command name');

        $this->createRegistry()->addCronJob(new class() extends Command {}, '* * * * *');
    }

    private function createRegistry(): CronJobsRegistry
    {
        $siteAccessService = self::createStub(SiteAccessServiceInterface::class);
        $siteAccessService->method('getCurrent')->willReturn(new SiteAccess('site'));

        return new CronJobsRegistry('prod', $siteAccessService);
    }
}
