<?php

namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class PluginInstall extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:install';
    protected static $defaultDescription = 'Install a plugin from a local zip file or URL';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Path to plugin zip or https:// URL')
            ->addOption('force',    'f',  InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE, 'Preview without making changes')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip backup of overwritten files');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io       = new SymfonyStyle($input, $output);
        $source   = $input->getArgument('source');
        $force    = $input->getOption('force');
        $dryRun   = $input->getOption('dry-run');
        $noBackup = $input->getOption('no-backup');

        if ($dryRun) $io->note('Dry-run mode: no files will be written.');

        // 1. Resolve source
        $zipPath = $this->resolveSource($source, $io);
        if ($zipPath === null) return Command::FAILURE;

        $zipHash = hash_file('sha256', $zipPath);

        // 2. Validate zip
        if (!$this->validateZip($zipPath, $io)) {
            $this->cleanupTemp('', $zipPath, $source);
            return Command::FAILURE;
        }

        // 3. Extract to temp
        $tempDir = sys_get_temp_dir() . '/webrium_plugin_' . uniqid();
        if (!$this->extractZip($zipPath, $tempDir)) {
            $io->error('Failed to extract zip archive.');
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 4. Read manifest
        $manifest = $this->readManifest($tempDir, $io);
        if ($manifest === null) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 5. Check if already installed
        $registry = $this->readRegistry();
        if ($this->findInRegistry($registry, $manifest['name']) !== null) {
            $io->error("Plugin '{$manifest['name']}' is already installed. Use plugin:update to upgrade.");
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 6. Build install plan
        $plan = $this->buildPlan($manifest, $tempDir, $io);
        if ($plan === null) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 7. Conflict check
        $conflicts = array_filter($plan, fn($e) => file_exists($e['dest']));
        if (!empty($conflicts) && !$force) {
            $io->warning('The following files already exist (use --force to overwrite):');
            foreach ($conflicts as $c) $io->writeln("  <fg=yellow>✖</> {$c['dest']}");
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 8. Show plan
        $io->title("{$manifest['name']} v{$manifest['version']}");
        $io->writeln('<fg=cyan>Files to be installed:</>');
        foreach ($plan as $entry) {
            $tag = file_exists($entry['dest']) ? '<fg=yellow>[overwrite]</>' : '<fg=green>[new]      </>';
            $io->writeln("  $tag {$entry['dest']}");
        }

        // 9. Show hooks
        if (!empty($manifest['hooks']['before_install'])) {
            $io->writeln('');
            $io->writeln('<fg=cyan>Before-install hooks:</>');
            foreach ($manifest['hooks']['before_install'] as $cmd) $io->writeln("  ⚙ $cmd");
        }
        if (!empty($manifest['hooks']['after_install'])) {
            $io->writeln('');
            $io->writeln('<fg=cyan>After-install hooks:</>');
            foreach ($manifest['hooks']['after_install'] as $cmd) $io->writeln("  ⚙ $cmd");
        }

        $io->writeln('');
        $this->printHash($zipPath, $io);

        if ($dryRun) {
            $io->success('Dry-run complete. No files were written.');
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::SUCCESS;
        }

        // 10. Before-install hooks
        if (!$this->runHooks($manifest['hooks'] ?? [], 'before_install', $io)) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 11. Backup conflicts
        if (!$noBackup && !empty($conflicts)) {
            $conflictPaths = array_column(array_values($conflicts), 'dest');
            $this->backupFiles($conflictPaths, $manifest['name'], 'pre-install', $io);
        }

        // 12. Copy files
        $installed = [];
        foreach ($plan as $entry) {
            @mkdir($entry['dest_dir'], 0755, true);
            if (!copy($entry['src'], $entry['dest'])) {
                $io->error("Failed to copy file to: {$entry['dest']}");
                $this->cleanupTemp($tempDir, $zipPath, $source);
                return Command::FAILURE;
            }
            $installed[] = $this->toRelativePath($entry['dest']);
            $io->writeln("<fg=green>✔ Installed:</> {$entry['dest']}");
        }

        if (!$this->runSqlFiles($manifest, $tempDir, $io)) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 13. After-install hooks
        if (!$this->runHooks($manifest['hooks'] ?? [], 'after_install', $io)) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 14. Register
        $registry['installed'][] = [
            'status' => 'active',  // disabled or active
            'name'         => $manifest['name'],
            'version'      => $manifest['version'],
            'description'  => $manifest['description'] ?? '',
            'author'       => $manifest['author'] ?? '',
            'installed_at' => date('Y-m-d H:i:s'),
            'hash'         => $zipHash,
            'files'        => $installed,
            'meta'         => $manifest['meta'] ?? [],
        ];

        $this->saveRegistry($registry);

        $this->cleanupTemp($tempDir, $zipPath, $source);
        $io->success("Plugin '{$manifest['name']}' v{$manifest['version']} installed successfully.");

        return Command::SUCCESS;
    }
}
