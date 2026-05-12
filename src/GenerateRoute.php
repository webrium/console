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

class GenerateRoute extends Command
{
    protected static $defaultName        = 'make:route';
    protected static $defaultDescription = 'Generate a new route file';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Route file name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the route already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io    = new SymfonyStyle($input, $output);
        $name  = $input->getArgument('name');
        $force = $input->getOption('force');

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid route name '$name'. Use only letters, numbers, and underscores.");
            return Command::FAILURE;
        }

        $routes_dir = Directory::path('routes');
        if (!is_dir($routes_dir)) {
            $io->error("Routes directory '$routes_dir' does not exist.");
            return Command::FAILURE;
        }
        if (!is_writable($routes_dir)) {
            $io->error("Routes directory '$routes_dir' is not writable.");
            return Command::FAILURE;
        }

        $file_name = "$name.php";
        $file_path = "$routes_dir/$file_name";

        if (File::exists($file_path) && !$force) {
            $io->error("Route '$file_name' already exists at '$file_path'. Use --force to overwrite.");
            return Command::FAILURE;
        }

        $template = File::getContent(__DIR__ . '/Files/Framework/Route.php');
        File::putContent($file_path, $template);

        $io->title('Route Generation');
        $io->writeln("<fg=green>✔ Route '$name' created successfully at '$file_path'.</>");

        return Command::SUCCESS;
    }
}