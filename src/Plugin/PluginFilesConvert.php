<?php

declare(strict_types=1);

namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

/**
 * Moves every installed plugin's file list between the two storage layouts:
 * inline in plugins.json (`files`) and one JSON file per plugin (`files_path`).
 * `plugin:files:split` and `plugin:files:merge` are the two directions; they
 * convert whatever is currently stored, regardless of the configured
 * `console.files_storage` mode (which only decides what new installs write).
 */
abstract class PluginFilesConvert extends Command
{
    use PluginHelper;

    abstract protected function toSplit(): bool;

    protected function configure(): void
    {
        Directory::initDefaultStructure();
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->initializePluginPaths($io)) {
            return Command::FAILURE;
        }

        // Force the target layout for this run only.
        $this->pluginPaths['files_storage'] = $this->toSplit()
            ? PluginPathConfig::FILES_STORAGE_SPLIT
            : PluginPathConfig::FILES_STORAGE_INLINE;

        $registry = $this->readRegistry();
        $dryRun = (bool) $input->getOption('dry-run');
        $converted = 0;

        foreach ($registry['installed'] as $index => $plugin) {
            $alreadyTarget = $this->toSplit() ? isset($plugin['files_path']) : isset($plugin['files']);
            if ($alreadyTarget) {
                continue;
            }

            $files = $this->pluginFiles($plugin);
            if ($files === null) {
                $io->error($this->missingFilesMessage($plugin));
                return Command::FAILURE;
            }

            $io->writeln(($dryRun ? '<fg=cyan>would convert:</> ' : '<fg=green>converted:</> ') . $plugin['name'] . ' (' . count($files) . ' files)');

            if (!$dryRun) {
                $plugin = $this->withStoredFiles($plugin, (string) $plugin['name'], $files);
                $registry['installed'][$index] = $plugin;
            }
            $converted++;
        }

        if ($converted === 0) {
            $io->success('Nothing to convert.');
            return Command::SUCCESS;
        }

        if (!$dryRun) {
            $this->saveRegistry($registry);
            $io->success("Converted $converted plugin(s). Run plugin:config:compile to refresh the compiled registry.");
        } else {
            $io->success('Dry-run complete. No files were written.');
        }

        return Command::SUCCESS;
    }
}
