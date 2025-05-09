<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Foxdb\DB;
use Webrium\Directory;

class DbAction extends Command
{
    protected static $defaultName = 'db';

    private const ACTION_LIST = 'list';
    private const ACTION_TABLES = 'tables';
    private const ACTION_CREATE = 'create';
    private const ACTION_DROP = 'drop';

    /**
     * Configures the command, defining the action argument and optional options.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Manage databases (actions: list, tables, create, drop)')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (list, tables, create, drop)')
            ->addArgument('name', InputArgument::OPTIONAL, 'Database name (required for create and drop actions)')
            ->addOption('use', 'u', InputOption::VALUE_OPTIONAL, 'Use a custom database for the tables action')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force database drop without confirmation');
    }

    /**
     * Executes the command to perform the specified database action.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        $actions = [
            self::ACTION_LIST => [$this, 'showAllDb'],
            self::ACTION_TABLES => [$this, 'showAllTables'],
            self::ACTION_CREATE => [$this, 'createNewTable'],
            self::ACTION_DROP => [$this, 'dropDatabase'],
        ];

        if (!isset($actions[$action])) {
            $io->error("Invalid action '$action'. Supported actions: " . implode(', ', array_keys($actions)) . ".");
            return Command::FAILURE;
        }

        return call_user_func($actions[$action], $input, $output);
    }

    /**
     * Displays a list of all databases.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function showAllDb(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $res = DB::query('SHOW DATABASES;', [], true);
            $rows = array_map(fn($row) => [$row->Database], $res); // Use -> instead of ['Database']
        } catch (\Exception $e) {
            $io->error("Failed to retrieve databases: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title('Database List');
        $io->writeln("<fg=cyan>Found " . count($rows) . " database(s):</>");

        $table = new Table($output);
        $table->setHeaders(['Database Name']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Displays a list of all tables in the specified or default database.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function showAllTables(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $use = $input->getOption('use');

        if ($use && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $use)) {
            $io->error("Invalid database name '$use'. Database names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        try {
            if ($use) {
                DB::useOnce($use);
            }
            $res = DB::query('SHOW TABLES;', [], true);
            $rows = array_map(fn($row) => [array_values((array)$row)[0]], $res); // Convert stdClass to array for table name
        } catch (\Exception $e) {
            $io->error("Failed to retrieve tables: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title('Table List' . ($use ? " (Database: $use)" : ''));
        $io->writeln("<fg=cyan>Found " . count($rows) . " table(s):</>");

        $table = new Table($output);
        $table->setHeaders(['Table Name']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Creates a new database with the specified name.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function createNewTable(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (empty($name)) {
            $io->error("Database name is required for the 'create' action.");
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid database name '$name'. Database names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        try {
            $status = DB::query("CREATE DATABASE IF NOT EXISTS `$name`");
            if (!$status) {
                $io->error("Failed to create database '$name'.");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Failed to create database '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Database '$name' created successfully.");
        return Command::SUCCESS;
    }

    /**
     * Drops the specified database after user confirmation.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function dropDatabase(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        $force = $input->getOption('force');

        if (empty($name)) {
            $io->error("Database name is required for the 'drop' action.");
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $io->error("Invalid database name '$name'. Database names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("<question>Are you sure you want to delete the '<error>$name</error>' database? (yes/no) [no]: </question>", false);
            if (!$helper->ask($input, $output, $question)) {
                $io->note("Database deletion cancelled.");
                return Command::SUCCESS;
            }
        }

        try {
            DB::query("DROP DATABASE IF EXISTS `$name`");
        } catch (\Exception $e) {
            $io->error("Failed to drop database '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Database '$name' deleted successfully.");
        return Command::SUCCESS;
    }
}