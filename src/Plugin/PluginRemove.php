<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class PluginRemove extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:remove';
    protected static $defaultDescription = 'Remove an installed plugin and its files';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Plugin name to remove')
            ->addOption('no-backup',   null, InputOption::VALUE_NONE, 'Skip backup before removal')
            ->addOption('keep-files',  null, InputOption::VALUE_NONE, 'Remove from registry only, keep files on disk');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io        = new SymfonyStyle($input, $output);
        $name      = $input->getArgument('name');
        $noBackup  = $input->getOption('no-backup');
        $keepFiles = $input->getOption('keep-files');

        // 1. Load registry
        $registry = $this->readRegistry();
        $plugin   = $this->findInRegistry($registry, $name);

        if ($plugin === null) {
            $io->error("Plugin '$name' is not installed.");
            return Command::FAILURE;
        }

        $io->title("Removing: {$plugin['name']} v{$plugin['version']}");

        if ($keepFiles) {
            $io->writeln('<fg=yellow>--keep-files: files will NOT be deleted from disk.</>');
        } else {
            $io->writeln('<fg=yellow>Files to be deleted:</>');
            foreach ($plugin['files'] as $file) {
                $exists = file_exists($file) ? '' : ' <fg=gray>(not found)</>';
                $io->writeln("  <fg=red>✖</> $file$exists");
            }
        }

        $io->writeln('');

        // 2. Confirm
        if (!$io->confirm("Are you sure you want to remove plugin '$name'?", false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        // 3. Backup
        if (!$noBackup && !$keepFiles) {
            $this->backupFiles($plugin['files'], $name, 'removed', $io);
        }

        // 4. Delete files
        if (!$keepFiles) {
            foreach ($plugin['files'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                    $io->writeln("<fg=red>✖ Deleted:</> $file");
                }
            }
        }

        // 5. Update registry
        $registry['installed'] = array_values(array_filter(
            $registry['installed'],
            fn($p) => $p['name'] !== $name
        ));
        $this->saveRegistry($registry);

        $io->success("Plugin '$name' removed successfully.");
        return Command::SUCCESS;
    }
}
