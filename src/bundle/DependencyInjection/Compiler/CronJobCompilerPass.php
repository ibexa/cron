<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\DependencyInjection\Compiler;

use Cron\CronExpression;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CronJobCompilerPass implements CompilerPassInterface
{
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
     * Registry is lazy - an invalid schedule would otherwise only fail on the first cron run, stopping all jobs.
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

        // dragonmantank/cron-expression accepts descending ranges (e.g. "22-2") but never matches them
        // and floods PHP warnings on every check, so reject them early.
        preg_match_all('/(\d+)-(\d+)/', $schedule, $ranges, PREG_SET_ORDER);
        foreach ($ranges as [$range, $start, $end]) {
            if ((int) $start > (int) $end) {
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
}
