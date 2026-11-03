<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Cron\Support;

use Cron\Exception\InvalidPatternException;
use DateTime;
use DateTimeImmutable;
use Throwable;

/**
 * SWAP POINT for IBX-13041 (1/2): the only place in the tests that reads cron/cron objects.
 * After cron/cron is replaced by dragonmantank/cron-expression + symfony/process, re-implement
 * these methods (and {@see ShellJobRegistry}); the tests themselves must stay untouched.
 */
final class CronLibraryAdapter
{
    /**
     * Whether a job returned by CronJobsRegistry::getCategoryCronJobs() is due at $now.
     */
    public static function isDue(
        object $job,
        DateTimeImmutable $now
    ): bool {
        return $job->getSchedule()->valid(DateTime::createFromImmutable($now));
    }

    /**
     * The shell command line of a job returned by CronJobsRegistry::getCategoryCronJobs().
     */
    public static function commandLine(object $job): string
    {
        return $job->getProcess()->getCommandLine();
    }

    /**
     * The exception CronJobsRegistry::addCronJob() throws for an invalid schedule.
     *
     * @return class-string<Throwable>
     */
    public static function invalidScheduleException(): string
    {
        return InvalidPatternException::class;
    }
}
