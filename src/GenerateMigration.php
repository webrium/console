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

class GenerateMigration extends Command
{
    protected static $defaultName = 'make:migration';

    private const CREATE_TEMPLATE = 'Files/Framework/MigrationCreate.php';
    private const UPDATE_TEMPLATE = 'Files/Framework/MigrationUpdate.php';

    /**
     * Configures the command, defining the migration name argument and optional table option.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Migration Name (e.g. create_users_table)');
        $this->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Table name used inside the migration stub', false);
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force overwrite if the migration already exists');
    }

    /**
     * Converts a camelCase, PascalCase or snake_case string to PascalCase.
     *
     * @param string $input The input string (e.g., create_users_table)
     * @return string The PascalCase string (e.g., CreateUsersTable)
     */
    private function convertToPascalCase(string $input): string
    {
        return str_replace('_', '', ucwords($input, '_'));
    }

    /**
     * Guesses the table name from a conventional migration name.
     *
     * create_users_table          -> users
     * add_status_to_users_table   -> users
     * remove_status_from_users_table -> users
     * users                        -> users
     *
     * @param string $name
     * @return string
     */
    private function guessTableName(string $name): string
    {
        if (preg_match('/_(?:to|from)_(\w+)$/', preg_replace('/_table$/', '', $name) ?? $name, $matches)) {
            return $matches[1];
        }

        $name = preg_replace('/^create_/', '', $name) ?? $name;
        $name = preg_replace('/_table$/', '', $name) ?? $name;

        return $name;
    }

    /**
     * Determines whether a migration name follows the "create table" convention
     * (as opposed to an "alter table" convention like add/remove/rename column).
     *
     * create_users_table          -> true
     * add_status_to_users_table   -> false
     * remove_status_from_users_table -> false
     *
     * @param string $name
     * @return bool
     */
    private function isCreateMigration(string $name): bool
    {
        if (preg_match('/_(?:to|from)_\w+_table$/', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Executes the command to generate a migration file.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $table = $input->getOption('table');
        $force = $input->getOption('force');

        // Validate migration name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid migration name '$name'. Migration names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        // Check directory
        $migrations_dir = Directory::path('migrations');
        if (!is_dir($migrations_dir)) {
            File::makeDirectory($migrations_dir, 0755, true);
        }
        if (!is_writable($migrations_dir)) {
            $io->error("The migrations directory '$migrations_dir' is not writable.");
            return Command::FAILURE;
        }

        // Check template file existence
        $root = __DIR__;
        $is_create = $this->isCreateMigration($name);
        $template_file = $is_create ? self::CREATE_TEMPLATE : self::UPDATE_TEMPLATE;
        if (!File::exists("$root/$template_file")) {
            $io->error("Template file '$template_file' not found.");
            return Command::FAILURE;
        }

        // Determine class name and table name
        $class_name = $this->convertToPascalCase($name);
        if (empty($table)) {
            $table = $this->guessTableName($name);
        }

        // Determine file name (timestamped, Laravel-style convention)
        $timestamp = date('Y_m_d_His');
        $file_name = "{$timestamp}_{$name}.php";
        $file_path = "$migrations_dir/$file_name";

        // Check if a migration with the same descriptive name already exists
        $existing = glob("$migrations_dir/*_{$name}.php");
        if (!empty($existing) && !$force) {
            $io->error("A migration named '$name' already exists at '{$existing[0]}'. Use --force to create another one anyway.");
            return Command::FAILURE;
        }

        // Read and modify template
        $migration_string = File::getContent("$root/$template_file");
        $migration_string = str_replace('MigrationClass', $class_name, $migration_string);
        $migration_string = str_replace('table_name', $table, $migration_string);

        // Write file
        File::putContent($file_path, $migration_string);

        // Output results
        $io->title('Migration Generation');
        $io->writeln("<fg=green>✔ Migration '$class_name' created successfully at '$file_path'.</>");
        $io->writeln("<fg=cyan>↳ Table: $table</>");

        return Command::SUCCESS;
    }
}
