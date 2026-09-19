<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;
use Webrium\Schedule;

/**
 * Runs a single registered task immediately, ignoring its own schedule —
 * for manually verifying a task works without waiting for (or faking) its
 * due time. Still goes through the same overlap lock and error isolation
 * as a real `schedule:run`.
 */
class ScheduleTestCommand extends Command
{
    protected static $defaultName        = 'schedule:test';
    protected static $defaultDescription = 'Run a single scheduled task immediately, ignoring its schedule';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'The task name to run (see schedule:list). Prompts to choose one if omitted.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        foreach (Schedule::loadDefault() as $failure) {
            $io->writeln("<fg=red>✘ Failed to load {$failure['file']}: {$failure['error']}</>");
        }

        $events = Schedule::all();

        if (empty($events)) {
            $io->writeln('<fg=cyan>No scheduled tasks registered.</>');
            return Command::SUCCESS;
        }

        $name = $input->getArgument('name');

        if ($name === null) {
            $names = array_map(static fn ($event) => $event->getName(), $events);
            $name  = $io->choice('Which scheduled task would you like to run?', $names);
        }

        $event = Schedule::find($name);

        if ($event === null) {
            $io->error("No scheduled task named '$name' found. Run schedule:list to see available tasks.");
            return Command::FAILURE;
        }

        $io->writeln("<fg=cyan>Running '{$event->getName()}'...</>");
        $result = Schedule::run($event);

        $line = match ($result['status']) {
            'ran'     => "<fg=green>✔ {$result['name']}</>",
            'skipped' => "<fg=yellow>⚠ {$result['name']} skipped ({$result['error']})</>",
            'failed'  => "<fg=red>✘ {$result['name']} failed: {$result['error']}</>",
            default   => "{$result['name']}: {$result['status']}",
        };
        $io->writeln($line);

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
