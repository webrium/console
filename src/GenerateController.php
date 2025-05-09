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

class GenerateController extends Command
{
    protected static $defaultName = 'make:controller';

    /**
     * Configures the command, defining the controller name argument and optional options.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Controller Name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the controller already exists')
            ->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'Custom namespace for the controller', 'App\Controllers');
    }

    /**
     * Executes the command to generate a controller file.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $force = $input->getOption('force');
        $namespace = $input->getOption('namespace');

        // Validate controller name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid controller name '$name'. Controller names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        // Append 'Controller' if not present
        if (!str_ends_with($name, 'Controller')) {
            $name .= 'Controller';
        }

        // Check controllers directory
        $controller_dir = Directory::path('controllers');
        if (!is_dir($controller_dir)) {
            $io->error("The controllers directory '$controller_dir' does not exist.");
            return Command::FAILURE;
        }
        if (!is_writable($controller_dir)) {
            $io->error("The controllers directory '$controller_dir' is not writable.");
            return Command::FAILURE;
        }

        // Generate file path
        $class_name = $name;
        $file_name = "$name.php";
        $file_path = "$controller_dir/$file_name";

        // Check if file exists
        if (File::exists($file_path) && !$force) {
            $io->error("The controller '$file_name' already exists at '$file_path'. Use --force to overwrite.");
            return Command::FAILURE;
        }

        // Generate controller content
        $controller_string = $this->getBasicStr($class_name, $namespace);

        // Write file
        File::putContent($file_path, $controller_string);

        // Output results
        $io->title('Controller Generation');
        $io->writeln("<fg=green>✔ Controller '$class_name' created successfully at '$file_path'.</>");
        $io->writeln("<fg=cyan>↳ Namespace: $namespace</>");

        return Command::SUCCESS;
    }

    /**
     * Generates the basic content for a controller file.
     *
     * @param string $name The controller class name
     * @param string $namespace The namespace for the controller
     * @return string The controller file content
     */
    private function getBasicStr(string $name, string $namespace): string
    {
        return "<?php\nnamespace $namespace;\n\nclass $name\n{\n    // Add your methods here\n}\n";
    }
}