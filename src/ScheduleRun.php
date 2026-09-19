<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;
use Webrium\Schedule;

/**
 * Runs every task registered under the 'schedules' directory (app/Schedules/
 * by default) that is due right now, then exits.
 *
 * Intended to be invoked by a single system cron entry once a minute:
 *
 *     * * * * * php /path/to/webrium schedule:run >> /dev/null 2>&1
 *
 * A broken task FILE (e.g. a syntax error) does not stop other files from
 * loading, and a task that throws while RUNNING does not stop other due
 * tasks from running — both are reported here, never fatal to the command.
 */
class ScheduleRun extends Command
{
    protected static $defaultName        = 'schedule:run';
    protected static $defaultDescription = 'Run every due scheduled task';

    protected function configure()
    {
        Directory::initDefaultStructure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $loadErrors = Schedule::loadDefault();
        foreach ($loadErrors as $failure) {
            $io->writeln("<fg=red>✘ Failed to load {$failure['file']}: {$failure['error']}</>");
        }

        $report = Schedule::runDue();

        if (empty($report)) {
            $io->writeln('<fg=cyan>No tasks due.</>');
            return empty($loadErrors) ? Command::SUCCESS : Command::FAILURE;
        }

        $hasFailure = !empty($loadErrors);

        foreach ($report as $task) {
            $line = match ($task['status']) {
                'ran'     => "<fg=green>✔ {$task['name']}</>",
                'skipped' => "<fg=yellow>⚠ {$task['name']} skipped ({$task['error']})</>",
                'failed'  => "<fg=red>✘ {$task['name']} failed: {$task['error']}</>",
                default   => "{$task['name']}: {$task['status']}",
            };
            $io->writeln($line);

            if ($task['status'] === 'failed') {
                $hasFailure = true;
            }
        }

        // Every due task always runs regardless of an earlier task's or
        // file's failure (see class docblock); the exit code below only
        // reports afterwards whether anything went wrong, for cron/monitoring.
        return $hasFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
