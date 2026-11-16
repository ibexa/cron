<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\DependencyInjection\Compiler;

use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Ibexa\Bundle\Cron\Registry\ScheduleValidator;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
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

        try {
            (new ScheduleValidator())->validate($schedule);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf('Invalid %s cron job schedule "%s", %s', $id, $schedule, $e->getMessage()), 0, $e);
        }
    }
}
