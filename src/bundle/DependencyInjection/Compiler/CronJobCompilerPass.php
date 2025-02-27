<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\DependencyInjection\Compiler;

use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
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
                    throw new \RuntimeException(sprintf('Invalid %s cron job configuration, schedule argument missing', $id));
                }

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
}
