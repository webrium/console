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
use Foxdb\Schema;
use Webrium\Directory;

class TableAction extends Command
{
    protected static $defaultName = 'table';

    private const ACTION_INFO = 'info';
    private const ACTION_COLUMNS = 'columns';
    private const ACTION_DROP = 'drop';

    /**
     * Configures the command, defining the action and table name arguments.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Manage tables (actions: info, columns, drop)')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (info, columns, drop)')
            ->addArgument('table_name', InputArgument::REQUIRED, 'Table name')
            ->addOption('use', 'u', InputOption::VALUE_OPTIONAL, 'Use a custom database')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force table drop without confirmation');
    }

    /**
     * Executes the command to perform the specified table action.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $table = $input->getArgument('table_name');

        // Validate table name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            $io->error("Invalid table name '$table'. Table names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        $actions = [
            self::ACTION_INFO => [$this, 'showTableColumns'],
            self::ACTION_COLUMNS => [$this, 'showTableColumns'],
            self::ACTION_DROP => [$this, 'dropTable'],
        ];

        if (!isset($actions[$action])) {
            $io->error("Invalid action '$action'. Supported actions: " . implode(', ', array_keys($actions)) . ".");
            $io->note("Run 'php webrium table -h' for help.");
            return Command::FAILURE;
        }

        return call_user_func($actions[$action], $input, $output);
    }

    /**
     * Displays the columns of the specified table.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function showTableColumns(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('table_name');
        $use = $input->getOption('use');

        if ($use && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $use)) {
            $io->error("Invalid database name '$use'. Database names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        try {
            if ($use) {
                DB::useOnce($use);
            }

            // Check if table exists
            $exists = DB::query("SHOW TABLES LIKE '$name';", [], true);
            if (empty($exists)) {
                $io->error("Table '$name' does not exist.");
                return Command::FAILURE;
            }

            $res = DB::query("DESCRIBE `$name`;", [], true);
            $rows = array_map(fn($row) => [
                $row->Field,
                $row->Type,
                $row->Null,
                $row->Key,
                $row->Default ?? 'NULL',
                $row->Extra
            ], $res);
        } catch (\Exception $e) {
            $io->error("Failed to retrieve table columns for '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Table Columns: $name");
        $io->writeln("<fg=cyan>Found " . count($rows) . " column(s):</>");

        $table = new Table($output);
        $table->setHeaders(['Name', 'Type', 'Null', 'Key', 'Default', 'Extra']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Drops the specified table after user confirmation.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function dropTable(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('table_name');
        $force = $input->getOption('force');
        $use = $input->getOption('use');

        if ($use && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $use)) {
            $io->error("Invalid database name '$use'. Database names must contain only letters, numbers, and underscores, and start with a letter or underscore.");
            return Command::FAILURE;
        }

        try {
            if ($use) {
                DB::useOnce($use);
            }

            // Check if table exists
            $exists = DB::query("SHOW TABLES LIKE '$name';", [], true);
            if (empty($exists)) {
                $io->error("Table '$name' does not exist.");
                return Command::FAILURE;
            }

            if (!$force) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion(
                    "<question>Are you sure you want to delete the '<error>$name</error>' table? (yes/no) [no]: </question>",
                    false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $io->note("Table deletion cancelled.");
                    return Command::SUCCESS;
                }
            }

            (new Schema($name))->drop();
        } catch (\Exception $e) {
            $io->error("Failed to drop table '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Table '$name' deleted successfully.");
        return Command::SUCCESS;
    }
}