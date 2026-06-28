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
use Foxdb\Seeders\SeederRunner;
use Foxdb\Seeders\SeederResult;
use Webrium\Directory;

class SeedAction extends Command
{
    protected static $defaultName = 'db:seed';

    /**
     * Configures the command, defining the optional class argument.
     */
    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->setDescription('Run database seeders (all or a single one)')
            ->addArgument('class', InputArgument::OPTIONAL, 'Seeder class or file name to run (omit to run all)', null)
            ->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'Named database connection to use', null)
            ->addOption('no-transaction', null, InputOption::VALUE_NONE, 'Do not wrap seeders in a transaction')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run without confirmation (useful in production)');
    }

    /**
     * Executes the seed command.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $seeders_dir = Directory::path('seeders');
        if (!is_dir($seeders_dir)) {
            $io->error("The seeders directory '$seeders_dir' does not exist. Run 'make:seeder' first.");
            return Command::FAILURE;
        }

        if (!$this->confirmIfProduction($input, $output)) {
            $io->note('Seeding cancelled.');
            return Command::SUCCESS;
        }

        $connection = $input->getOption('connection');
        $runner = new SeederRunner($seeders_dir, $connection);

        if ($input->getOption('no-transaction')) {
            $runner->useTransaction(false);
        }

        $class = $input->getArgument('class');

        if ($class !== null) {
            return $this->runOne($io, $output, $runner, $class);
        }

        return $this->runAll($io, $output, $runner);
    }

    /**
     * Runs all seeders in the seeders directory.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function runAll(SymfonyStyle $io, OutputInterface $output, SeederRunner $runner): int
    {
        $files = $runner->getSeederFiles();

        if (empty($files)) {
            $io->writeln('<info>No seeders found.</info>');
            return Command::SUCCESS;
        }

        $results = $runner->runAll();
        return $this->renderResults($io, $output, $results);
    }

    /**
     * Runs a single seeder by class name or file name.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function runOne(SymfonyStyle $io, OutputInterface $output, SeederRunner $runner, string $class): int
    {
        // Try as a class first (if it's already autoloadable), otherwise as a file.
        $result = class_exists($class)
            ? $runner->runClass($class)
            : $runner->runFile($class);

        return $this->renderResults($io, $output, [$result]);
    }

    /**
     * Renders an array of SeederResult into a table and reports the outcome.
     *
     * @param  array<int, SeederResult> $results
     * @return int Command::SUCCESS or Command::FAILURE
     */
    private function renderResults(SymfonyStyle $io, OutputInterface $output, array $results): int
    {
        if (empty($results)) {
            return Command::SUCCESS;
        }

        $io->title('Seeding');

        $rows = array_map(function (SeederResult $result) {
            return [
                $result->name,
                $result->success ? '<fg=green>OK</>' : '<fg=red>FAILED</>',
                number_format($result->timeMs, 2) . ' ms',
            ];
        }, $results);

        $table = new Table($output);
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

    /**
     * Asks for confirmation when running in a production-like environment,
     * unless --force was passed.
     *
     * @return bool
     */
    private function confirmIfProduction(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->getOption('force')) {
            return true;
        }

        $env = strtolower((string) (getenv('APP_ENV') ?: ''));
        if ($env !== 'production' && $env !== 'prod') {
            return true;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            "<question>Application is in production. Run seeders anyway? (yes/no) [no]: </question>",
            false
        );

        return (bool) $helper->ask($input, $output, $question);
    }
}
