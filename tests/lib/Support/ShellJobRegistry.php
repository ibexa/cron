<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Cron\Support;

use Cron\Job\ShellJob;
use Cron\Schedule\CrontabSchedule;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;

/**
 * Lets tests register arbitrary shell commands as jobs.
 */
final class ShellJobRegistry extends CronJobsRegistry
{
    public function addShellJob(
        string $schedule,
        string $shellCommand,
        string $category = self::DEFAULT_CATEGORY
    ): void {
        $job = new ShellJob();
        $job->setSchedule(new CrontabSchedule($schedule));
        $job->setCommand($shellCommand);

        $this->cronJobs[$category][] = $job;
    }
}
