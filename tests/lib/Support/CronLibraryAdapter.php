<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Cron\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Test helpers over the registry's CronExpression: due check and job command line.
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
        return $job->schedule->isDue($now);
    }

    /**
     * The shell command line of a job returned by CronJobsRegistry::getCategoryCronJobs().
     */
    public static function commandLine(object $job): string
    {
        return $job->command;
    }

    /**
     * The exception CronJobsRegistry::addCronJob() throws for an invalid schedule.
     *
     * @return class-string<Throwable>
     */
    public static function invalidScheduleException(): string
    {
        return InvalidArgumentException::class;
    }
}
