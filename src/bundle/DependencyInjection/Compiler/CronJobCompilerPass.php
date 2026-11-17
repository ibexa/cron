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

        $messages = array_filter([
            $this->checkDayOfMonthAndDayOfWeek($fields),
            $this->checkDayOfMonthOrMonthStep($fields),
            ...$this->checkStepsBeyondRange($fields),
            $this->checkDayOfWeekSeven($fields),
            ...$this->checkDescendingRanges($schedule),
            $this->checkYearField($fields),
        ]);

        foreach ($messages as $message) {
            trigger_deprecation('ibexa/cron', '5.0.11', 'Cron job "%s" schedule "%s" %s', $id, $schedule, $message);
        }
    }

    /**
     * @param list<string> $fields
     */
    private function checkDayOfMonthAndDayOfWeek(array $fields): ?string
    {
        if ($fields[2] === '*' || $fields[4] === '*') {
            return null;
        }

        return 'restricts both day-of-month and day-of-week. They are matched with AND; since 6.0 they are matched with OR (standard cron). Restrict only one of them to keep the current behaviour.';
    }

    /**
     * @param list<string> $fields
     */
    private function checkDayOfMonthOrMonthStep(array $fields): ?string
    {
        if (!str_contains($fields[2], '*/') && !str_contains($fields[3], '*/')) {
            return null;
        }

        return 'uses a step (*/n) in day-of-month or month. Since 6.0 steps count from the first value of the field (day 1, month 1), currently from 0.';
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function checkStepsBeyondRange(array $fields): array
    {
        $messages = [];
        foreach ([0 => ['minute', 60], 1 => ['hour', 24]] as $position => [$name, $size]) {
            // A step beyond the field range matches only 0 now, 6.0 wraps it around (*/90 minutes -> minute 30)
            if (preg_match('#\*/(\d+)#', $fields[$position], $step) === 1 && (int) $step[1] >= $size && (int) $step[1] % $size !== 0) {
                $messages[] = sprintf(
                    'uses a step "*/%d" larger than the %s range. It currently matches only %s 0; since 6.0 it matches %s %d. Use 0 to keep the current behaviour.',
                    $step[1],
                    $name,
                    $name,
                    $name,
                    (int) $step[1] % $size
                );
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $fields
     */
    private function checkDayOfWeekSeven(array $fields): ?string
    {
        if (preg_match('/(^|[,-])7(,|$)/', $fields[4]) !== 1) {
            return null;
        }

        return 'uses 7 in day-of-week. Since 6.0 it means Sunday, currently it never matches.';
    }

    /**
     * @return list<string>
     */
    private function checkDescendingRanges(string $schedule): array
    {
        $messages = [];
        preg_match_all('/(\d+)-(\d+)/', $schedule, $ranges, PREG_SET_ORDER);
        foreach ($ranges as [$range, $start, $end]) {
            if ((int) $start > (int) $end) {
                $messages[] = sprintf('uses a descending range "%s". It currently matches every value between the two bounds; since 6.0 such a schedule is rejected. Use an ascending range or a list instead.', $range);
            }
        }

        return $messages;
    }

    /**
     * @param list<string> $fields
     */
    private function checkYearField(array $fields): ?string
    {
        return isset($fields[5]) ? 'has a 6th (year) field. Since 6.0 it is rejected, currently it is ignored.' : null;
    }
}
