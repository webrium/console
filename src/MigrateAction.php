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
use Foxdb\Migrations\Migrator;
use Foxdb\Migrations\MigrationResult;
use Foxdb\Seeders\SeederRunner;
use Foxdb\Seeders\SeederResult;
use Webrium\Directory;

class MigrateAction extends Command
{
    protected static $defaultName = 'migrate';

    private const ACTION_RUN = 'run';
    private const ACTION_ROLLBACK = 'rollback';
    private const ACTION_RESET = 'reset';
    private const ACTION_REFRESH = 'refresh';
    private const ACTION_STATUS = 'status';

    /**
     * Configures the command, defining the action argument and optional options.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Run database migrations (actions: run, rollback, reset, refresh, status)')
            ->addArgument('action', InputArgument::OPTIONAL, 'Action to perform (run, rollback, reset, refresh, status)', self::ACTION_RUN)
            ->addOption('step', null, InputOption::VALUE_OPTIONAL, 'Number of migrations to run or roll back', null)
            ->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Named database connection to use', null)
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Run seeders after a successful run or refresh')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the operation without confirmation');
    }

    /**
     * Executes the command to perform the specified migration action.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        $actions = [
            self::ACTION_RUN => [$this, 'runMigrations'],
            self::ACTION_ROLLBACK => [$this, 'rollbackMigrations'],
            self::ACTION_RESET => [$this, 'resetMigrations'],
            self::ACTION_REFRESH => [$this, 'refreshMigrations'],
            self::ACTION_STATUS => [$this, 'showStatus'],
        ];

        if (!isset($actions[$action])) {
            $io->error("Invalid action '$action'. Supported actions: " . implode(', ', array_keys($actions)) . ".");
            return Command::FAILURE;
        }

        $migrations_dir = Directory::path('migrations');
        if (!is_dir($migrations_dir)) {
            $io->error("The migrations directory '$migrations_dir' does not exist. Run 'make:migration' first.");
            return Command::FAILURE;
        }

        $connection = $input->getOption('connection');
        $migrator = new Migrator($migrations_dir, 'migrations', $connection);

        return call_user_func($actions[$action], $input, $output, $migrator);
    }

    /**
     * Runs all pending migrations.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function runMigrations(InputInterface $input, OutputInterface $output, Migrator $migrator): int
    {
        $io = new SymfonyStyle($input, $output);
        $step = $this->resolveStep($input);

        if (!$migrator->hasPendingMigrations()) {
            $io->writeln('<info>Nothing to migrate.</info>');
            return Command::SUCCESS;
        }

        $results = $migrator->run($step);

        $status = $this->renderResults($io, $results, 'Migrating');

        if ($status === Command::SUCCESS && $input->getOption('seed')) {
            return $this->runSeeders($input, $output, $io);
        }

        return $status;
    }

    /**
     * Rolls back the last batch (or N steps) of migrations.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function rollbackMigrations(InputInterface $input, OutputInterface $output, Migrator $migrator): int
    {
        $io = new SymfonyStyle($input, $output);
        $step = $this->resolveStep($input);

        $results = $migrator->rollback($step);

        if (empty($results)) {
            $io->writeln('<info>Nothing to roll back.</info>');
            return Command::SUCCESS;
        }

        return $this->renderResults($io, $results, 'Rolling back');
    }

    /**
     * Rolls back every migration that has been run.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function resetMigrations(InputInterface $input, OutputInterface $output, Migrator $migrator): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->confirmDestructive($input, $output, 'This will roll back ALL migrations. Continue?')) {
            $io->note('Migration reset cancelled.');
            return Command::SUCCESS;
        }

        $results = $migrator->reset();

        if (empty($results)) {
            $io->writeln('<info>Nothing to roll back.</info>');
            return Command::SUCCESS;
        }

        return $this->renderResults($io, $results, 'Rolling back');
    }

    /**
     * Rolls back every migration and re-runs them from scratch.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function refreshMigrations(InputInterface $input, OutputInterface $output, Migrator $migrator): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->confirmDestructive($input, $output, 'This will roll back and re-run ALL migrations. Continue?')) {
            $io->note('Migration refresh cancelled.');
            return Command::SUCCESS;
        }

        $result = $migrator->refresh();

        $down_status = $this->renderResults($io, $result['down'], 'Rolling back');
        $up_status = $this->renderResults($io, $result['up'], 'Migrating');

        return ($down_status === Command::SUCCESS && $up_status === Command::SUCCESS)
            ? ($input->getOption('seed') ? $this->runSeeders($input, $output, $io) : Command::SUCCESS)
            : Command::FAILURE;
    }

    /**
     * Displays the status of every migration file.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function showStatus(InputInterface $input, OutputInterface $output, Migrator $migrator): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $migrator->status();

        if (empty($status)) {
            $io->writeln('<info>No migration files found.</info>');
            return Command::SUCCESS;
        }

        $rows = array_map(function (array $row) {
            return [
                $row['name'],
                $row['ran'] ? '<fg=green>Yes</>' : '<fg=yellow>No</>',
                $row['batch'] ?? '-',
            ];
        }, $status);

        $io->title('Migration Status');
        $table = new Table($output);
        $table->setHeaders(['Migration', 'Ran', 'Batch']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Reads the --step option as an int, or null when not provided.
     *
     * @return int|null
     */
    private function resolveStep(InputInterface $input): ?int
    {
        $step = $input->getOption('step');
        return $step !== null ? (int) $step : null;
    }

    /**
     * Asks for confirmation before a destructive action, unless --force was passed.
     *
     * @return bool
     */
    private function confirmDestructive(InputInterface $input, OutputInterface $output, string $message): bool
    {
        if ($input->getOption('force')) {
            return true;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion("<question>$message (yes/no) [no]: </question>", false);

        return (bool) $helper->ask($input, $output, $question);
    }

    /**
     * Renders an array of MigrationResult into a table and reports the outcome.
     *
     * @param  array<int, MigrationResult> $results
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function renderResults(SymfonyStyle $io, array $results, string $title): int
    {
        if (empty($results)) {
            return Command::SUCCESS;
        }

        $io->title($title);

        $rows = array_map(function (MigrationResult $result) {
            return [
                $result->name,
                $result->success ? '<fg=green>OK</>' : '<fg=red>FAILED</>',
                number_format($result->timeMs, 2) . ' ms',
            ];
        }, $results);

        $table = new Table($io);
        $table->setHeaders(['Migration', 'Status', 'Time']);
        $table->setRows($rows);
        $table->render();

        $failed = array_filter($results, fn(MigrationResult $r) => !$r->success);

        if (!empty($failed)) {
            foreach ($failed as $result) {
                $io->error("{$result->name}: {$result->error}");
            }
            return Command::FAILURE;
        }

        $io->success(ucfirst($title) . ' completed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Runs all seeders found in the seeders directory.
     * Used when the --seed flag is passed to migrate run / refresh.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function runSeeders(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $seeders_dir = Directory::path('seeders');
        if (!is_dir($seeders_dir)) {
            $io->writeln('<info>No seeders directory; skipping --seed.</info>');
            return Command::SUCCESS;
        }

        $runner = new SeederRunner($seeders_dir, $input->getOption('connection'));

        $files = $runner->getSeederFiles();
        if (empty($files)) {
            $io->writeln('<info>No seeders to run.</info>');
            return Command::SUCCESS;
        }

        $results = $runner->runAll();

        $io->title('Seeding');

        $rows = array_map(function (SeederResult $result) {
            return [
                $result->name,
                $result->success ? '<fg=green>OK</>' : '<fg=red>FAILED</>',
                number_format($result->timeMs, 2) . ' ms',
            ];
        }, $results);

        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['Seeder', 'Status', 'Time']);
        $table->setRows($rows);
        $table->render();

        $failed = array_filter($results, fn(SeederResult $r) => !$r->success);

        if (!empty($failed)) {
            foreach ($failed as $result) {
                $io->error("{$result->name}: {$result->error}");
            }
            return Command::FAILURE;
        }

        $io->success('Seeding completed successfully.');
        return Command::SUCCESS;
    }
}
