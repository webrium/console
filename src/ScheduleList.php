<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;
use Webrium\Schedule;

/**
 * Lists every registered scheduled task with its cron expression and next
 * due time — a read-only diagnostic, does not run anything.
 */
class ScheduleList extends Command
{
    protected static $defaultName        = 'schedule:list';
    protected static $defaultDescription = 'List all registered scheduled tasks';

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

        $events = Schedule::all();

        if (empty($events)) {
            $io->writeln('<fg=cyan>No scheduled tasks registered.</>');
            return empty($loadErrors) ? Command::SUCCESS : Command::FAILURE;
        }

        $rows = [];
        foreach ($events as $event) {
            $next = $event->nextRunDate();
            $rows[] = [
                $event->getName(),
                $event->getExpression(),
                $next !== null ? $next->format('Y-m-d H:i') : 'unknown',
            ];
        }

        $table = new Table($output);
        $table->setHeaders(['Task', 'Expression', 'Next Due']);
        $table->setRows($rows);
        $table->render();

        return empty($loadErrors) ? Command::SUCCESS : Command::FAILURE;
    }
}
