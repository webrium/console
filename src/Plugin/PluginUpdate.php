<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class PluginUpdate extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:update';
    protected static $defaultDescription = 'Update an installed plugin from a zip file or URL';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Path to new plugin zip or https:// URL')
            ->addOption('force',     'f',  InputOption::VALUE_NONE, 'Update even if version is same or older')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip backup before updating');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io       = new SymfonyStyle($input, $output);
        $source   = $input->getArgument('source');
        $force    = $input->getOption('force');
        $noBackup = $input->getOption('no-backup');

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
        $tempDir = sys_get_temp_dir() . '/webrium_plugin_upd_' . uniqid();
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

        // 5. Check registry
        $registry = $this->readRegistry();
        $existing = $this->findInRegistry($registry, $manifest['name']);

        if ($existing === null) {
            $io->error("Plugin '{$manifest['name']}' is not installed. Use plugin:install instead.");
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        $currentVersion = $existing['version'];
        $newVersion     = $manifest['version'];

        // 6. Hash check
        if (isset($existing['hash']) && $existing['hash'] === $zipHash && !$force) {
            $io->warning('This zip is identical to the currently installed version (same SHA-256).');
            $io->writeln('Use <fg=cyan>--force</> to reinstall anyway.');
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 7. Version check
        if (!$force && version_compare($newVersion, $currentVersion, '<=')) {
            $io->warning("New version ($newVersion) is not newer than installed ($currentVersion).");
            $io->writeln('Use <fg=cyan>--force</> to update anyway.');
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        $io->title("Updating: {$manifest['name']}");
        $io->writeln("  Installed : <fg=yellow>v$currentVersion</>");
        $io->writeln("  New       : <fg=green>v$newVersion</>");
        $io->writeln('');
        $this->printHash($zipPath, $io);

        // 8. Build plan
        $plan = $this->buildPlan($manifest, $tempDir, $io);
        if ($plan === null) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 9. Before-update hooks
        if (!$this->runHooks($manifest['hooks'] ?? [], 'before_install', $io)) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 10. Backup current files
        if (!$noBackup) {
            $this->backupFiles($existing['files'], $manifest['name'], "v{$currentVersion}", $io);
        }

        // 11. Remove obsolete files (in old install but not in new plan)
        $newInstalledPaths = array_map(
            fn($entry) => $this->normalizeRegistryPath($this->toRelativePath($entry['dest'])),
            $plan
        );
        foreach ($existing['files'] as $oldFile) {
            $oldFileAbs = $this->toAbsolutePath($oldFile);
            $oldRegistryPath = $this->normalizeRegistryPath($this->toRelativePath($oldFileAbs));
            if (!in_array($oldRegistryPath, $newInstalledPaths, true) && file_exists($oldFileAbs)) {
                unlink($oldFileAbs);
                $io->writeln("<fg=red>✖ Removed obsolete file:</> $oldFile");
            }
        }

        // 12. Copy new files
        $installed = [];
        foreach ($plan as $entry) {
            @mkdir($entry['dest_dir'], 0755, true);
            if (!copy($entry['src'], $entry['dest'])) {
                $io->error("Failed to copy file to: {$entry['dest']}");
                $this->cleanupTemp($tempDir, $zipPath, $source);
                return Command::FAILURE;
            }
            $installed[] = $this->toRelativePath($entry['dest']);
            $io->writeln("<fg=green>✔ Updated:</> {$entry['dest']}");
        }

        // 13. After-update hooks
        if (!$this->runHooks($manifest['hooks'] ?? [], 'after_install', $io)) {
            $this->cleanupTemp($tempDir, $zipPath, $source);
            return Command::FAILURE;
        }

        // 14. Update registry in place. Package-owned fields are refreshed,
        // while project/runtime fields and unknown extension fields survive.
        $updatedPlugin = array_replace($existing, [
            'name'         => $manifest['name'],
            'version'      => $newVersion,
            'description'  => $manifest['description'] ?? '',
            'author'       => $manifest['author'] ?? '',
            'installed_at' => $existing['installed_at'] ?? date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
            'hash'         => $zipHash,
            'files'        => $installed,
            'meta'         => $manifest['meta'] ?? [],
        ]);

        foreach ($registry['installed'] as $index => $plugin) {
            if ($plugin['name'] === $manifest['name']) {
                $registry['installed'][$index] = $updatedPlugin;
                break;
            }
        }
        $this->saveRegistry($registry);

        $this->cleanupTemp($tempDir, $zipPath, $source);
        $io->success("Plugin '{$manifest['name']}' updated to v$newVersion successfully.");

        return Command::SUCCESS;
    }

    private function normalizeRegistryPath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
