<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\Cron\Command;

use DateTimeImmutable;
use DateTimeZone;
use Ibexa\Bundle\Cron\Command\CronRunCommand;
use Ibexa\Core\MVC\Symfony\SiteAccess\SiteAccessServiceInterface;
use Ibexa\Tests\Cron\Support\ShellJobRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CronRunCommand::class)]
final class CronRunCommandTest extends TestCase
{
    private const EVERY_MINUTE = '* * * * *';

    private ShellJobRegistry $registry;

    private string | false $originalTz;

    protected function setUp(): void
    {
        $this->originalTz = getenv('TZ');
        $this->registry = new ShellJobRegistry('test', self::createStub(SiteAccessServiceInterface::class));
    }

    protected function tearDown(): void
    {
        putenv($this->originalTz === false ? 'TZ' : 'TZ=' . $this->originalTz);
    }

    public function testDueJobStdoutIsLoggedAsInfoWithCommandAndExitCode(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo hello');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with("hello\n", ['command' => 'echo hello', 'exitCode' => 0]);
        $logger->expects(self::never())->method('error');

        self::assertSame(Command::SUCCESS, $this->runCron($logger));
    }

    public function testJobThatIsNotDueIsNotRun(): void
    {
        // The executor reads "now" in the TZ env variable, so pin it to UTC to make "+30 minutes" never due.
        putenv('TZ=UTC');
        $this->registry->addShellJob(sprintf('%d * * * *', (intdiv(time(), 60) % 60 + 30) % 60), 'echo should-not-run');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('error');

        $this->runCron($logger);
    }

    public function testFailingJobLogsStdoutAsInfoAndTrimmedStderrAsError(): void
    {
        $command = 'echo out; echo "  err  " >&2; exit 1';
        $this->registry->addShellJob(self::EVERY_MINUTE, $command);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with("out\n", ['command' => $command, 'exitCode' => 1]);
        $logger->expects(self::once())->method('error')->with('err', ['command' => $command, 'exitCode' => 1]);

        // A failing job does not make the cron command itself fail.
        self::assertSame(Command::SUCCESS, $this->runCron($logger));
    }

    public function testStderrOfSuccessfulJobIsNotLogged(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo warning >&2');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('error');

        $this->runCron($logger);
    }

    public function testBlankStderrOfFailedJobIsSkipped(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo "   " >&2; exit 1');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('error');

        $this->runCron($logger);
    }

    /**
     * Pitfall: the "!empty()" check treats a stderr chunk of exactly "0" as empty, so it is dropped.
     */
    public function testStderrConsistingOfZeroIsDropped(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'printf 0 >&2; exit 1');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('error');

        $this->runCron($logger);
    }

    public function testDueJobsRunInParallel(): void
    {
        // Each job prints its start and end timestamp around "sleep 1"; the trailing comment only makes the command lines distinct.
        $stamp = escapeshellarg('echo microtime(true), "\n";');
        $command = sprintf('%1$s -r %2$s; sleep 1; %1$s -r %2$s', escapeshellarg(PHP_BINARY), $stamp);
        $this->registry->addShellJob(self::EVERY_MINUTE, $command . ' # job 1');
        $this->registry->addShellJob(self::EVERY_MINUTE, $command . ' # job 2');

        $output = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('info')->willReturnCallback(
            static function (
                string $message,
                array $context
            ) use (&$output): void {
                $output[$context['command']] = ($output[$context['command']] ?? '') . $message;
            }
        );
        $logger->expects(self::never())->method('error');

        $this->runCron($logger);

        self::assertCount(2, $output);
        [$start1, $end1] = array_map('floatval', explode("\n", trim(array_shift($output))));
        [$start2, $end2] = array_map('floatval', explode("\n", trim(array_shift($output))));
        self::assertLessThan($end1, $start2, 'job 2 must start before job 1 ends');
        self::assertLessThan($end2, $start1, 'job 1 must start before job 2 ends');
    }

    public function testSignalKilledJobDoesNotAbortTheRun(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo first; kill -9 $$');
        $this->registry->addShellJob(self::EVERY_MINUTE, 'sleep 1; echo second-done');

        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info')->willReturnCallback(
            static function (
                string $message,
                array $context
            ) use (&$logged): void {
                $logged[$message] = $context['exitCode'];
            }
        );
        $logger->expects(self::never())->method('error');

        self::assertSame(Command::SUCCESS, $this->runCron($logger));
        self::assertSame(["first\n" => 137, "second-done\n" => 0], $logged);
    }

    /**
     * Pitfall-adjacent: output of a job is drained while other jobs are still running, so a chatty job
     * is not blocked behind a slow one.
     */
    #[Group('slow')]
    public function testChattyJobFinishesWithoutWaitingForSlowJob(): void
    {
        $php = escapeshellarg(PHP_BINARY);
        $this->registry->addShellJob(self::EVERY_MINUTE, 'sleep 3; echo slow-done');
        $this->registry->addShellJob(
            self::EVERY_MINUTE,
            sprintf("head -c 300000 /dev/zero | tr '\\0' a; echo; %s -r 'echo \"marker:\", microtime(true);'", $php)
        );

        $output = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('info')->willReturnCallback(
            static function (
                string $message,
                array $context
            ) use (&$output): void {
                $output[$context['command']] = ($output[$context['command']] ?? '') . $message;
            }
        );
        $logger->expects(self::never())->method('error');

        $start = microtime(true);
        $this->runCron($logger);
        $total = microtime(true) - $start;

        self::assertGreaterThanOrEqual(3.0, $total);
        $chatty = array_values(array_filter($output, static fn (string $o): bool => str_contains($o, 'marker:')));
        self::assertCount(1, $chatty);
        self::assertGreaterThan(300000, strlen($chatty[0]));
        self::assertSame(1, preg_match('/marker:([\d.]+)$/', $chatty[0], $m));
        self::assertLessThan(2.0, (float)$m[1] - $start);
        self::assertContains("slow-done\n", $output);
    }

    /**
     * Pitfall: the 60s Process timeout is never checked, because the command only polls isRunning(),
     * so a long job is neither killed nor reported as failed.
     */
    #[Group('slow')]
    public function testJobRunningLongerThanDefaultTimeoutIsNotKilled(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'sleep 62; echo finished');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with("finished\n", ['command' => 'sleep 62; echo finished', 'exitCode' => 0]);
        $logger->expects(self::never())->method('error');

        $start = microtime(true);
        $this->runCron($logger);

        self::assertGreaterThanOrEqual(62.0, microtime(true) - $start);
    }

    public function testCategoryOptionSelectsJobs(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo default-job');
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo reports-job', 'reports');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with("reports-job\n", self::anything());
        $logger->expects(self::never())->method('error');

        $this->runCron($logger, ['--category' => 'reports']);
    }

    public function testUnknownCategoryRunsNothing(): void
    {
        $this->registry->addShellJob(self::EVERY_MINUTE, 'echo default-job');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('error');

        self::assertSame(Command::SUCCESS, $this->runCron($logger, ['--category' => 'missing']));
    }

    /**
     * The executor evaluates "now" in the TZ environment variable, falling back to the PHP default timezone (UTC here).
     * Hour pattern covers the current and next hour so that crossing an hour boundary mid-test cannot flake it.
     */
    public function testTzEnvironmentVariableDecidesWhichHourIsNow(): void
    {
        $kiritimatiHour = (int)(new DateTimeImmutable('now', new DateTimeZone('Pacific/Kiritimati')))->format('G');
        $pattern = sprintf('* %d,%d * * *', $kiritimatiHour, ($kiritimatiHour + 1) % 24);
        $this->registry->addShellJob($pattern, 'echo tz-job');

        putenv('TZ=Pacific/Kiritimati');
        $due = $this->createMock(LoggerInterface::class);
        $due->expects(self::once())->method('info')->with("tz-job\n", self::anything());
        $due->expects(self::never())->method('error');
        $this->runCron($due);

        putenv('TZ=UTC');
        $notDue = $this->createMock(LoggerInterface::class);
        $notDue->expects(self::never())->method('info');
        $notDue->expects(self::never())->method('error');
        $this->runCron($notDue);

        putenv('TZ');
        $fallback = $this->createMock(LoggerInterface::class);
        $fallback->expects(self::never())->method('info');
        $fallback->expects(self::never())->method('error');
        $this->runCron($fallback);
    }

    /**
     * @param array<string, string> $input
     */
    private function runCron(
        LoggerInterface $logger,
        array $input = []
    ): int {
        $tester = new CommandTester(new CronRunCommand($logger, $this->registry));

        return $tester->execute($input);
    }
}
