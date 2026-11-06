<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\DependencyInjection\Compiler;

use Cron\CronExpression;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;

class CronJobCompilerPass implements CompilerPassInterface
{
    /** Month and day-of-week names as dragonmantank/cron-expression numbers them (SUN is 7). */
    private const array RANGE_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6, 'SUN' => 7,
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(CronJobsRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(CronJobsRegistry::class);
        $cronJobs = $container->findTaggedServiceIds('ibexa.cron.job');

        foreach ($cronJobs as $id => $tags) {
            foreach ($tags as $cronJob) {
                $reference = new Reference($id);

                if (empty($cronJob['schedule'])) {
                    throw new RuntimeException(sprintf('Invalid %s cron job configuration, schedule argument missing', $id));
                }

                $this->validateSchedule($container, $id, $cronJob['schedule']);

                $cronJob['category'] = $cronJob['category'] ?? CronJobsRegistry::DEFAULT_CATEGORY;

                $registry->addMethodCall('addCronJob', [
                    $reference,
                    $cronJob['schedule'],
                    $cronJob['category'],
                    $cronJob['options'] ?? '',
                ]);
            }
        }
    }

    /**
     * Validated at compile time so an invalid schedule fails the container build instead of the first
     * ibexa:cron:run, where it would stop all jobs.
     */
    private function validateSchedule(
        ContainerBuilder $container,
        string $id,
        string $schedule
    ): void {
        $schedule = $container->getParameterBag()->resolveValue($schedule);
        // %env()% values are known only at runtime, the registry rejects an invalid one on the first cron run.
        if (!is_string($schedule) || $container->resolveEnvPlaceholders($schedule, '%%env(%s)%%') !== $schedule) {
            return;
        }

        // dragonmantank/cron-expression accepts descending ranges (e.g. "22-2", "FRI-MON") but never matches them
        // (hour/minute ones also flood PHP warnings on every check), so reject them early.
        preg_match_all('/([a-z\d]+)-([a-z\d]+)/i', $schedule, $ranges, PREG_SET_ORDER);
        foreach ($ranges as [$range, $start, $end]) {
            if ($this->rangeBound($start) > $this->rangeBound($end)) {
                throw new RuntimeException(sprintf(
                    'Invalid %s cron job schedule "%s", descending range "%s" is not supported. Use an ascending range or a list instead.',
                    $id,
                    $schedule,
                    $range
                ));
            }
        }

        if (!CronExpression::isValidExpression($schedule)) {
            throw new RuntimeException(sprintf('Invalid %s cron job schedule "%s"', $id, $schedule));
        }
    }

    /**
     * Unknown names map to 0, isValidExpression() rejects them.
     */
    private function rangeBound(string $bound): int
    {
        return ctype_digit($bound) ? (int) $bound : self::RANGE_NAMES[strtoupper($bound)] ?? 0;
    }
}
