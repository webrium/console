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

    /**
     * Resolve a temporary connection name (or null for the default) when the
     * `--use` option is provided.
     *
     * FoxDB v5 removed DB::useOnce(). To preserve the original behavior of
     * "use this database only for this command, then restore the default",
     * we register a temporary connection derived from the current default's
     * config with the `database` key overridden. The caller is responsible
     * for calling releaseScopedConnection() in a `finally` block to drop it.
     *
     * @param  string|null $use  The database name from --use (already validated)
     * @return string|null       The temporary connection name, or null when
     *                           no scope is needed (use was not requested).
     */
    private function makeScopedConnection(?string $use): ?string
    {
        if ($use === null) {
            return null;
        }

        $config = DB::connection()->getConfig();
        $config['database'] = $use;
        $name = '__console_use_' . $use;
        DB::addConnection($config, $name);

        return $name;
    }

    /**
     * Drop a previously registered scoped connection.
     * Safe to call with null — it becomes a no-op.
     *
     * @param  string|null $name
     * @return void
     */
    private function releaseScopedConnection(?string $name): void
    {
        if ($name !== null) {
            DB::disconnect($name);
        }
    }

    private function showColumns(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $name = $input->getArgument('table_name');
        $use  = $input->getOption('use');

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            // FoxDB v5: DB::select() returns array<int, object> (FETCH_OBJ).
            // The third argument targets a named connection without affecting
            // the default — perfect replacement for the removed DB::useOnce().
            $res  = DB::select("DESCRIBE `$name`;", [], $scope);
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
        } finally {
            $this->releaseScopedConnection($scope);
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

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            if (!$force && !$io->confirm("Drop table '$name'? This cannot be undone.", false)) {
                $io->note('Cancelled.');
                return Command::SUCCESS;
            }

            // FoxDB v5 Schema still accepts a table name in its constructor
            // and exposes drop(). This builds and runs a DROP TABLE statement.
            (new Schema($name))->drop();

            // Verify using DB::select() (FoxDB v5 — DB::query() was removed).
            $still = DB::select("SHOW TABLES LIKE '$name';", [], $scope);
            if (!empty($still)) {
                $io->error("Drop command ran but table '$name' still exists.");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Failed to drop '$name': " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
        }

        $io->success("Table '$name' dropped.");
        return Command::SUCCESS;
    }

    private function truncateTable(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $name  = $input->getArgument('table_name');
        $force = $input->getOption('force');
        $use   = $input->getOption('use');

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            // FoxDB v5: use DB::select() for SELECT queries.
            $countRow = DB::select("SELECT COUNT(*) as total FROM `$name`;", [], $scope);
            $count = $countRow[0]->total ?? 0;

            if (!$force && !$io->confirm("Truncate '$name'? All $count row(s) will be deleted.", false)) {
                $io->note('Cancelled.');
                return Command::SUCCESS;
            }

            // FoxDB v5: use DB::statement() for DDL/DDL-like statements (TRUNCATE).
            DB::statement("TRUNCATE TABLE `$name`;", $scope);
        } catch (\Exception $e) {
            $io->error("Failed to truncate '$name': " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
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

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            // FoxDB v5: use DB::statement() for DDL (RENAME TABLE).
            DB::statement("RENAME TABLE `$name` TO `$newName`;", $scope);
        } catch (\Exception $e) {
            $io->error("Failed to rename '$name': " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
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

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            // FoxDB v5: SHOW TABLES is a SELECT — use DB::select().
            $destExists = DB::select("SHOW TABLES LIKE '$newName';", [], $scope);
            if (!empty($destExists)) {
                $io->error("Table '$newName' already exists.");
                return Command::FAILURE;
            }

            // FoxDB v5: CREATE TABLE ... LIKE is DDL — use DB::statement().
            DB::statement("CREATE TABLE `$newName` LIKE `$name`;", $scope);
        } catch (\Exception $e) {
            $io->error("Failed to copy '$name': " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
        }

        $io->success("Table '$name' structure copied to '$newName'.");
        return Command::SUCCESS;
    }

    private function tableExists(InputInterface $input, SymfonyStyle $io): int
    {
        $name = $input->getArgument('table_name');
        $use  = $input->getOption('use');

        $scope = $this->makeScopedConnection($use);

        try {
            // FoxDB v5: SHOW TABLES is a SELECT — use DB::select().
            $exists = DB::select("SHOW TABLES LIKE '$name';", [], $scope);
        } catch (\Exception $e) {
            $io->error("DB error: " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
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

        $scope = $this->makeScopedConnection($use);

        try {
            if (!$this->checkExists($name, $io, $scope)) return Command::FAILURE;

            // FoxDB v5: SELECT COUNT(*) — use DB::select().
            $countRow = DB::select("SELECT COUNT(*) as total FROM `$name`;", [], $scope);
            $count = $countRow[0]->total ?? 0;
        } catch (\Exception $e) {
            $io->error("Failed to count rows in '$name': " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
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

        $scope = $this->makeScopedConnection($use);

        try {
            // FoxDB v5: use DB::statement() for raw SQL (DDL or DML) — DB::query()
            // was removed. statement() executes via PDO::exec() and returns bool.
            DB::statement($sql, $scope);
        } catch (\Exception $e) {
            $io->error("SQL execution failed: " . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->releaseScopedConnection($scope);
        }

        $io->success("SQL file executed: $path");
        return Command::SUCCESS;
    }

    /**
     * Check whether a table exists on the (optionally scoped) connection.
     *
     * @param  string      $name   Table name (already validated).
     * @param  SymfonyStyle $io    Output for error messages.
     * @param  string|null $scope  Temporary connection name (null = default).
     * @return bool
     */
    private function checkExists(string $name, SymfonyStyle $io, ?string $scope = null): bool
    {
        // FoxDB v5: SHOW TABLES LIKE is a SELECT — use DB::select().
        $exists = DB::select("SHOW TABLES LIKE '$name';", [], $scope);
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