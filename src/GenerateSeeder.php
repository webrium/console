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

class GenerateSeeder extends Command
{
    protected static $defaultName = 'make:seeder';

    private const TEMPLATE = 'Files/Framework/SeederFile.php';

    /**
     * Configures the command, defining the seeder name argument.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Create a new database seeder class')
            ->addArgument('name', InputArgument::REQUIRED, 'Seeder name (e.g. UsersSeeder)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the seeder already exists');
    }

    /**
     * Converts a snake_case or kebab-case name to PascalCase.
     *
     * @param string $input
     * @return string
     */
    private function convertToPascalCase(string $input): string
    {
        $input = str_replace('-', '_', $input);
        return str_replace('_', '', ucwords($input, '_'));
    }

    /**
     * Executes the command to generate a seeder file.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $force = $input->getOption('force');

        // Validate seeder name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid seeder name '$name'. Names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        // Resolve directory
        $seeders_dir = Directory::path('seeders');
        if (!is_dir($seeders_dir)) {
            File::makeDirectory($seeders_dir, 0755, true);
        }
        if (!is_writable($seeders_dir)) {
            $io->error("The seeders directory '$seeders_dir' is not writable.");
            return Command::FAILURE;
        }

        // Check template
        $root = __DIR__;
        $template_file = self::TEMPLATE;
        if (!File::exists("$root/$template_file")) {
            $io->error("Template file '$template_file' not found.");
            return Command::FAILURE;
        }

        // Class & file name
        $class_name = $this->convertToPascalCase($name);
        $file_name = "{$class_name}.php";
        $file_path = "$seeders_dir/$file_name";

        // Existence check
        if (file_exists($file_path) && !$force) {
            $io->error("A seeder named '$class_name' already exists at '$file_path'. Use --force to overwrite.");
            return Command::FAILURE;
        }

        // Render template
        $seeder_string = File::getContent("$root/$template_file");
        $seeder_string = str_replace('SeederClass', $class_name, $seeder_string);

        // Write
        File::putContent($file_path, $seeder_string);

        // Output
        $io->title('Seeder Generation');
        $io->writeln("<fg=green>✔ Seeder '$class_name' created successfully at '$file_path'.</>");

        return Command::SUCCESS;
    }
}
