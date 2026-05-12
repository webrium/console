<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class InitWebrium extends Command
{
    protected static $defaultName        = 'init';
    protected static $defaultDescription = 'Create the project directory structure';

    protected function configure() {}

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $dirs =Directory::makes();

        $io->title('Project Initialization');
        $io->writeln('<fg=green>✔ Project structure created successfully.</>');
        $io->newLine();

        
        foreach ($dirs as $key => $path) {
            $io->writeln("  <fg=cyan>" . ($key + 1) . "</>  →  ".Directory::path($path));
        }

        $io->newLine();
        $io->success('Run "php webrium list" to see all available commands.');

        return Command::SUCCESS;
    }
}