<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\File;
use Webrium\Directory;

class GenerateSchedule extends Command
{
    protected static $defaultName        = 'make:schedule';
    protected static $defaultDescription = 'Generate a new scheduled task file';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Schedule file name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the file already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io    = new SymfonyStyle($input, $output);
        $name  = $input->getArgument('name');
        $force = $input->getOption('force');

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid schedule name '$name'. Use only letters, numbers, and underscores.");
            return Command::FAILURE;
        }

        $schedules_dir = Directory::path('schedules');
        if (!is_dir($schedules_dir)) {
            File::makeDirectory($schedules_dir);
        }
        if (!is_writable($schedules_dir)) {
            $io->error("Schedules directory '$schedules_dir' is not writable.");
            return Command::FAILURE;
        }

        $file_name = "$name.php";
        $file_path = "$schedules_dir/$file_name";

        if (File::exists($file_path) && !$force) {
            $io->error("Schedule '$file_name' already exists at '$file_path'. Use --force to overwrite.");
            return Command::FAILURE;
        }

        $template = File::getContent(__DIR__ . '/Files/Framework/Schedule.php');
        File::putContent($file_path, $template);

        $io->title('Schedule Generation');
        $io->writeln("<fg=green>✔ Schedule '$name' created successfully at '$file_path'.</>");

        return Command::SUCCESS;
    }
}
