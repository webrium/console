<?php
namespace Webrium\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Foxdb\DB;
use Foxdb\Schema;
use Webrium\Directory;

class TableAction extends Command
{
    protected static $defaultName        = 'table';
    protected static $defaultDescription = 'Manage tables (actions: info, columns, drop, truncate, rename, copy, exists, count, run)';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: info, columns, drop, truncate, rename, copy, exists, count, run')
            ->addArgument('table_name', InputArgument::REQUIRED, 'Table name (or SQL file path for the run action)')
            ->addArgument('extra', InputArgument::OPTIONAL, 'Extra argument (new name for rename/copy)')
            ->addOption('use',   'u', InputOption::VALUE_OPTIONAL, 'Target database name')
            ->addOption('force', 'f', InputOption::VALUE_NONE,     'Skip confirmation prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        $actions = ['info', 'columns', 'drop', 'truncate', 'rename', 'copy', 'exists', 'count', 'run'];

        if (!in_array($action, $actions, true)) {
            $io->error("Invalid action '$action'. Supported actions: " . implode(', ', $actions) . '.');
            return Command::FAILURE;
        }

        // 'run' uses table_name as a file path — skip table name validation
        if ($action !== 'run') {
            $table = $input->getArgument('table_name');
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                $io->error("Invalid table name '$table'.");
                return Command::FAILURE;
            }
        }

        if (!$this->validateDbOption($input, $io)) return Command::FAILURE;

        return match ($action) {
            'info', 'columns' => $this->showColumns($input, $output, $io),
            'drop'            => $this->dropTable($input, $output, $io),
            'truncate'        => $this->truncateTable($input, $output, $io),
            'rename'          => $this->renameTable($input, $io),
            'copy'            => $this->copyTable($input, $io),
            'exists'          => $this->tableExists($input, $io),
            'count'           => $this->countRows($input, $io),
            'run'             => $this->runSql($input, $io),
        };
    }

    private function showColumns(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $name = $input->getArgument('table_name');
        $use  = $input->getOption('use');

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            $res  = DB::query("DESCRIBE `$name`;", [], true);
            $rows = array_map(fn($row) => [
                $row->Field,
                $row->Type,
                $row->Null,
                $row->Key,
                $row->Default ?? 'NULL',
                $row->Extra,
            ], $res);
        } catch (\Exception $e) {
            $io->error("Failed to describe '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $db = $use ? " <fg=gray>(db: $use)</>" : '';
        $io->title("Table: $name$db");
        $io->writeln('<fg=cyan>' . count($rows) . ' column(s)</>');

        $table = new Table($output);
        $table->setHeaders(['Name', 'Type', 'Null', 'Key', 'Default', 'Extra']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    private function dropTable(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $name  = $input->getArgument('table_name');
        $force = $input->getOption('force');
        $use   = $input->getOption('use');

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            if (!$force && !$io->confirm("Drop table '$name'? This cannot be undone.", false)) {
                $io->note('Cancelled.');
                return Command::SUCCESS;
            }

            (new Schema($name))->drop();

            // Verify
            $still = DB::query("SHOW TABLES LIKE '$name';", [], true);
            if (!empty($still)) {
                $io->error("Drop command ran but table '$name' still exists.");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Failed to drop '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Table '$name' dropped.");
        return Command::SUCCESS;
    }

    private function truncateTable(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $name  = $input->getArgument('table_name');
        $force = $input->getOption('force');
        $use   = $input->getOption('use');

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            $count = DB::query("SELECT COUNT(*) as total FROM `$name`;", [], true)[0]->total ?? 0;

            if (!$force && !$io->confirm("Truncate '$name'? All $count row(s) will be deleted.", false)) {
                $io->note('Cancelled.');
                return Command::SUCCESS;
            }

            DB::query("TRUNCATE TABLE `$name`;");
        } catch (\Exception $e) {
            $io->error("Failed to truncate '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Table '$name' truncated.");
        return Command::SUCCESS;
    }

    private function renameTable(InputInterface $input, SymfonyStyle $io): int
    {
        $name    = $input->getArgument('table_name');
        $newName = $input->getArgument('extra');
        $use     = $input->getOption('use');

        if (empty($newName)) {
            $io->error("New table name is required. Usage: table rename <old_name> <new_name>");
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newName)) {
            $io->error("Invalid new table name '$newName'.");
            return Command::FAILURE;
        }

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            DB::query("RENAME TABLE `$name` TO `$newName`;");
        } catch (\Exception $e) {
            $io->error("Failed to rename '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Table '$name' renamed to '$newName'.");
        return Command::SUCCESS;
    }

    private function copyTable(InputInterface $input, SymfonyStyle $io): int
    {
        $name    = $input->getArgument('table_name');
        $newName = $input->getArgument('extra');
        $use     = $input->getOption('use');

        if (empty($newName)) {
            $io->error("New table name is required. Usage: table copy <source> <destination>");
            return Command::FAILURE;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $newName)) {
            $io->error("Invalid destination table name '$newName'.");
            return Command::FAILURE;
        }

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            $destExists = DB::query("SHOW TABLES LIKE '$newName';", [], true);
            if (!empty($destExists)) {
                $io->error("Table '$newName' already exists.");
                return Command::FAILURE;
            }

            DB::query("CREATE TABLE `$newName` LIKE `$name`;");
        } catch (\Exception $e) {
            $io->error("Failed to copy '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Table '$name' structure copied to '$newName'.");
        return Command::SUCCESS;
    }

    private function tableExists(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('table_name');
        $use  = $input->getOption('use');

        try {
            if ($use) DB::useOnce($use);
            $exists = DB::query("SHOW TABLES LIKE '$name';", [], true);
        } catch (\Exception $e) {
            $io->error("DB error: " . $e->getMessage());
            return Command::FAILURE;
        }

        if (!empty($exists)) {
            $io->writeln("<fg=green>✔ Table '$name' exists.</>");
        } else {
            $io->writeln("<fg=yellow>✖ Table '$name' does not exist.</>");
        }

        return Command::SUCCESS;
    }

    private function countRows(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('table_name');
        $use  = $input->getOption('use');

        try {
            if ($use) DB::useOnce($use);

            if (!$this->checkExists($name, $io)) return Command::FAILURE;

            $count = DB::query("SELECT COUNT(*) as total FROM `$name`;", [], true)[0]->total ?? 0;
        } catch (\Exception $e) {
            $io->error("Failed to count rows in '$name': " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln("Table <fg=cyan>$name</>: <fg=green>$count</> row(s).");
        return Command::SUCCESS;
    }

    private function runSql(InputInterface $input, SymfonyStyle $io): int
    {
        $path = $input->getArgument('table_name'); // reused as file path
        $use  = $input->getOption('use');

        if (!file_exists($path)) {
            $io->error("SQL file not found: '$path'");
            return Command::FAILURE;
        }

        $sql = file_get_contents($path);
        if (empty(trim($sql))) {
            $io->error("SQL file is empty: '$path'");
            return Command::FAILURE;
        }

        try {
            if ($use) DB::useOnce($use);
            DB::statement($sql);
        } catch (\Exception $e) {
            $io->error("SQL execution failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success("SQL file executed: $path");
        return Command::SUCCESS;
    }

    private function checkExists(string $name, SymfonyStyle $io): bool
    {
        $exists = DB::query("SHOW TABLES LIKE '$name';", [], true);
        if (empty($exists)) {
            $io->error("Table '$name' does not exist.");
            return false;
        }
        return true;
    }

    private function validateDbOption(InputInterface $input, SymfonyStyle $io): bool
    {
        $use = $input->getOption('use');
        if ($use && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $use)) {
            $io->error("Invalid database name '$use'.");
            return false;
        }
        return true;
    }
}