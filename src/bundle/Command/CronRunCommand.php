<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\Cron\Command;

use DateTimeImmutable;
use DateTimeZone;
use Ibexa\Bundle\Cron\Registry\CronJobsRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'ibexa:cron:run', description: 'Perform one-time cron tasks run.')]
final class CronRunCommand extends Command
{
    private CronJobsRegistry $cronJobsRegistry;

    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger,
        CronJobsRegistry $cronJobsRegistry,
        ?string $name = null
    ) {
        parent::__construct($name);

        $this->logger = $logger;
        $this->cronJobsRegistry = $cronJobsRegistry;
    }

    protected function configure(): void
    {
        $this
            ->setDefinition([
                new InputOption('category', null, InputOption::VALUE_REQUIRED, 'Job category to run', 'default'),
            ])
            ->setHelp(
                <<<EOT
It's not meant to be run manually, yet it's OK to do so as it still might be useful for development purpose.

Check documentation how to setup it be called automatically.
EOT
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $category = $input->getOption('category');
        $cronJobs = $this->cronJobsRegistry->getCategoryCronJobs($category);

        $now = new DateTimeImmutable('now', new DateTimeZone(getenv('TZ') ?: date_default_timezone_get()));

        /** @var list<Process> $processes */
        $processes = [];
        /** @var array<int, list<string>> $outputs */
        $outputs = [];
        /** @var array<int, list<string>> $errors */
        $errors = [];
        foreach ($cronJobs as $job) {
            if (!$job->schedule->isDue($now)) {
                continue;
            }

            $index = count($processes);
            $outputs[$index] = [];
            $errors[$index] = [];

            // Jobs may legitimately run long, do not kill them (no timeout).
            $process = Process::fromShellCommandline($job->command, timeout: null);
            $process->start(static function (
                string $type,
                string $buffer
            ) use ($index, &$outputs, &$errors): void {
                if (Process::ERR === $type) {
                    $errors[$index][] = $buffer;
                } else {
                    $outputs[$index][] = $buffer;
                }
            });
            $processes[] = $process;
        }

        // Poll instead of wait(): wait() throws ProcessSignaledException for a job killed by a signal (aborting the
        // run and killing the other jobs) and, by reading one process at a time, lets the others block on full pipes.
        // isRunning() never throws and drains every process's pipes.
        while (array_filter($processes, static fn (Process $p): bool => $p->isRunning()) !== []) {
            usleep(10000);
        }

        foreach ($processes as $index => $process) {
            $this->logRun($process, $outputs[$index], $errors[$index]);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $output
     * @param list<string> $errors
     */
    private function logRun(
        Process $process,
        array $output,
        array $errors
    ): void {
        $extraInfo = [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
        ];
        foreach ($output as $chunk) {
            $this->logger->info($chunk, $extraInfo);
        }
        if (!$process->isSuccessful()) {
            foreach ($errors as $chunk) {
                $chunk = trim($chunk);
                if ($chunk !== '') {
                    $this->logger->error($chunk, $extraInfo);
                }
            }
        }
    }
}
