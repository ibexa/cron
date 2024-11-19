<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\Registry;

use Cron\Job\ShellJob;
use Cron\Schedule\CrontabSchedule;
use Ibexa\Core\MVC\Symfony\SiteAccess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\PhpExecutableFinder;

class CronJobsRegistry
{
    public const string DEFAULT_CATEGORY = 'default';

    /**
     * @var array<string, \Cron\Job\ShellJob[]>
     */
    protected array $cronJobs = [];

    protected string $executable;

    protected string $environment;

    protected SiteAccessServiceInterface $siteAccessService;

    protected string $options;

    public function __construct(string $environment, SiteAccess $siteaccess)
    {
        $finder = new PhpExecutableFinder();

        $phpBinary = $finder->find();
        if (false === $phpBinary) {
            throw new \LogicException('CronJobsRegistry: Unable to find PHP binary');
        }

        $this->executable = $phpBinary;
        $this->environment = $environment;
        $this->siteaccess = $siteaccess;
    }

    public function addCronJob(Command $command, string $schedule = null, string $category = self::DEFAULT_CATEGORY, string $options = ''): void
    {
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
            $this->siteaccess->name,
            $this->environment
        );

        $job = new ShellJob();
        $job->setSchedule(new CrontabSchedule($schedule));
        $job->setCommand($command);

        $this->cronJobs[$category][] = $job;
    }

    /**
     * @return \Cron\Job\ShellJob[]
     */
    public function getCategoryCronJobs(string $category): array
    {
        return $this->cronJobs[$category] ?? [];
    }
}
