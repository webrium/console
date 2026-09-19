<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;
use Webrium\Schedule;

/**
 * Runs the scheduler loop in the foreground, without needing a real system
 * cron entry — mainly for local development. Wakes up at the start of every
 * minute, reloads the schedule directory from scratch (so edits to task
 * files take effect without restarting the worker) and runs whatever is
 * due, exactly like a `schedule:run` invoked by cron every minute would.
 *
 * Not a replacement for real cron in production: this single process must
 * itself stay alive — an OS cron entry restarts on its own each minute even
 * if the previous run crashed; this worker does not restart itself if it
 * dies mid-tick.
 *
 * Stops gracefully (finishes the current tick, then exits) on Ctrl+C /
 * SIGTERM when the pcntl extension is available; otherwise the process just
 * exits immediately, same as any other command would.
 */
class ScheduleWork extends Command
{
    protected static $defaultName        = 'schedule:work';
    protected static $defaultDescription = 'Run the scheduler in the foreground, ticking once a minute (local dev — use real cron + schedule:run in production)';

    private bool $stop = false;

    /**
     * Test-only escape hatch: caps the number of ticks so the loop can be
     * asserted on without running forever. Never set outside tests.
     */
    private ?int $maxTicksForTesting = null;

    protected function configure()
    {
        Directory::initDefaultStructure();
    }

    /**
     * @internal Test-only. Stops the loop after $ticks iterations instead
     *           of running until interrupted.
     */
    public function limitTicksForTesting(int $ticks): void
    {
        $this->maxTicksForTesting = $ticks;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('<fg=cyan>Schedule worker started. Press Ctrl+C to stop.</>');

        $this->registerSignalHandlers();

        $ticks = 0;

        while (!$this->stop) {
            $this->sleepUntilNextMinute();

            if ($this->stop) {
                break;
            }

            $this->tick($io);
            $ticks++;

            if ($this->maxTicksForTesting !== null && $ticks >= $this->maxTicksForTesting) {
                break;
            }
        }

        $io->writeln('<fg=cyan>Schedule worker stopped.</>');

        return Command::SUCCESS;
    }

    /**
     * Sleeps until the top of the next minute. Overridden by tests to avoid
     * a real (up to 59-second) sleep.
     */
    protected function sleepUntilNextMinute(): void
    {
        $secondsIntoMinute = (int) (new \DateTimeImmutable())->format('s');
        $remaining         = 60 - $secondsIntoMinute;

        if ($remaining > 0) {
            sleep($remaining);
        }
    }

    /**
     * One iteration: forget everything registered on the previous tick (a
     * long-running process would otherwise re-register — and re-run — every
     * task file on every single tick, since loadFromDirectory() always
     * re-requires each file), reload the schedule directory fresh, then run
     * whatever is due.
     */
    protected function tick(SymfonyStyle $io): void
    {
        Schedule::reset();

        foreach (Schedule::loadDefault() as $failure) {
            $io->writeln("<fg=red>✘ Failed to load {$failure['file']}: {$failure['error']}</>");
        }

        foreach (Schedule::runDue() as $task) {
            $line = match ($task['status']) {
                'ran'     => "<fg=green>✔ {$task['name']}</>",
                'skipped' => "<fg=yellow>⚠ {$task['name']} skipped ({$task['error']})</>",
                'failed'  => "<fg=red>✘ {$task['name']} failed: {$task['error']}</>",
                default   => "{$task['name']}: {$task['status']}",
            };
            $io->writeln('[' . date('Y-m-d H:i:s') . '] ' . $line);
        }
    }

    private function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void { $this->stop = true; });
        pcntl_signal(SIGTERM, function (): void { $this->stop = true; });
    }
}
