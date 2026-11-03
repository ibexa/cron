<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace Ibexa\Bundle\Cron\DependencyInjection\Compiler;

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

                $this->triggerScheduleDeprecations($id, $cronJob['schedule']);

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

    private function triggerScheduleDeprecations(
        string $id,
        string $schedule
    ): void {
        $fields = preg_split('/\s+/', trim($schedule)) ?: [];
        if (str_starts_with($schedule, '@') || count($fields) < 5) {
            return;
        }

        $messages = [];
        if ($fields[2] !== '*' && $fields[4] !== '*') {
            $messages[] = 'restricts both day-of-month and day-of-week. They are matched with AND; since 6.0 they are matched with OR (standard cron). Restrict only one of them to keep the current behaviour.';
        }
        if (str_contains($fields[2], '*/') || str_contains($fields[3], '*/')) {
            $messages[] = 'uses a step (*/n) in day-of-month or month. Since 6.0 steps count from the first value of the field (day 1, month 1), currently from 0.';
        }
        if (preg_match('/(^|[,-])7(,|$)/', $fields[4]) === 1) {
            $messages[] = 'uses 7 in day-of-week. Since 6.0 it means Sunday, currently it never matches.';
        }
        preg_match_all('/(\d+)-(\d+)/', $schedule, $ranges, PREG_SET_ORDER);
        foreach ($ranges as [$range, $start, $end]) {
            if ((int) $start > (int) $end) {
                $messages[] = sprintf('uses a descending range "%s". It currently matches every value between the two bounds; since 6.0 such a schedule is rejected. Use an ascending range or a list instead.', $range);
            }
        }
        if (isset($fields[5])) {
            $messages[] = 'has a 6th (year) field. Since 6.0 it is rejected, currently it is ignored.';
        }

        foreach ($messages as $message) {
            trigger_deprecation('ibexa/cron', '5.0.11', 'Cron job "%s" schedule "%s" %s', $id, $schedule, $message);
        }
    }
}
