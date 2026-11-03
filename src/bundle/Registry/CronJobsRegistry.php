<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\Registry;

use Cron\Job\ShellJob;
use Cron\Schedule\CrontabSchedule;
use Ibexa\Core\MVC\Symfony\SiteAccess\SiteAccessServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\PhpExecutableFinder;

class CronJobsRegistry
{
    public const string DEFAULT_CATEGORY = 'default';

    /**
     * @var array<string, ShellJob[]>
     *
     * @deprecated 5.0.x Relying on \Cron\Job\ShellJob elements is deprecated. Since 6.0 the elements are \Ibexa\Bundle\Cron\Registry\CronJob, as the cron/cron dependency is dropped.
     */
    protected array $cronJobs = [];

    protected string $executable;

    protected string $environment;

    protected SiteAccessServiceInterface $siteAccessService;

    protected string $options;

    public function __construct(
        string $environment,
        SiteAccessServiceInterface $siteAccessService
    ) {
        $finder = new PhpExecutableFinder();

        $phpBinary = $finder->find();
        if (false === $phpBinary) {
            throw new \LogicException('CronJobsRegistry: Unable to find PHP binary');
        }

        $this->executable = $phpBinary;
        $this->environment = $environment;
        $this->siteAccessService = $siteAccessService;
    }

    public function addCronJob(
        Command $command,
        ?string $schedule = null,
        string $category = self::DEFAULT_CATEGORY,
        string $options = ''
    ): void {
        if (null === $schedule) {
            trigger_deprecation(
                'ibexa/cron',
                '5.0.x',
                'Passing null as $schedule to %s() is deprecated. Since 6.0 an \InvalidArgumentException is thrown.',
                __METHOD__
            );
        }

        $commandName = $command->getName();
        if (null === $commandName) {
            throw new \LogicException('CronJobsRegistry: Unable to get a command name');
        }

        $command = sprintf(
            '%s %s %s %s --siteaccess=%s --env=%s',
            $this->executable,
            $_SERVER['SCRIPT_NAME'],
            $commandName,
            $options,
            $this->siteAccessService->getCurrent()->name,
            $this->environment
        );

        $job = new ShellJob();
        $job->setSchedule(new CrontabSchedule($schedule));
        $job->setCommand($command);

        $this->cronJobs[$category][] = $job;
    }

    /**
     * @return ShellJob[]
     *
     * @deprecated 5.0.x Relying on returned \Cron\Job\ShellJob objects is deprecated. Since 6.0 the method returns list<\Ibexa\Bundle\Cron\Registry\CronJob>, as the cron/cron dependency is dropped.
     */
    public function getCategoryCronJobs(string $category): array
    {
        return $this->cronJobs[$category] ?? [];
    }
}
