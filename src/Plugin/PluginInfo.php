<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Webrium\Directory;

class PluginInfo extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:info';
    protected static $defaultDescription = 'Preview plugin details and install plan without installing';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this->addArgument('source', InputArgument::REQUIRED, 'Path to plugin zip or https:// URL');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io     = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source');

        // 1. Resolve source
        $zipPath = $this->resolveSource($source, $io);
        if ($zipPath === null) return Command::FAILURE;

        // 2. Validate zip
        if (!$this->validateZip($zipPath, $io)) {
            $this->cleanupTemp('', $zipPath, $source);
            return Command::FAILURE;
        }

        // 3. Extract to temp
        $tempDir = sys_get_temp_dir() . '/webrium_plugin_info_' . uniqid();
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

        // 5. Display plugin metadata
        $io->title('Plugin Information');
        $io->definitionList(
            ['Name'        => $manifest['name']],
            ['Version'     => $manifest['version']],
            ['Description' => $manifest['description'] ?? '-'],
            ['Author'      => $manifest['author']      ?? '-'],
        );

        // 6. Install status
        $registry = $this->readRegistry();
        $existing = $this->findInRegistry($registry, $manifest['name']);
        if ($existing) {
            $io->writeln("<fg=yellow>⚠ Already installed:</> v{$existing['version']}");
        } else {
            $io->writeln('<fg=green>✔ Not yet installed</>');
        }
        $io->writeln('');

        // 7. File install plan
        $io->section('Files to be installed');
        $table = new Table($output);
        $table->setHeaders(['Source (in zip/src)', 'Destination', 'Overwrite']);

        foreach ($manifest['files'] as $entry) {
            $destDir = Directory::path($entry['dest'] ?? '') ?? "[unknown key: {$entry['dest']}]";
            if (!empty($entry['subpath'])) {
                $sub     = preg_replace('/\.\.[\\/]/', '', $entry['subpath']);
                $destDir = $destDir . '/' . ltrim($sub, '/\\');
            }
            $destPath  = $destDir . '/' . basename($entry['src'] ?? '');
            $overwrite = ($entry['overwrite'] ?? false) ? '<fg=yellow>yes</>' : 'no';
            $conflict  = file_exists($destPath) ? ' <fg=red>[EXISTS]</>' : '';

            $table->addRow([
                $entry['src'] ?? '?',
                $destPath . $conflict,
                $overwrite,
            ]);
        }
        $table->render();

        // 8. Hooks
        if (!empty($manifest['hooks'])) {
            $io->section('Hooks');
            foreach (['before_install', 'after_install'] as $stage) {
                if (!empty($manifest['hooks'][$stage])) {
                    $io->writeln("<fg=cyan>$stage:</>");
                    foreach ($manifest['hooks'][$stage] as $cmd) {
                        $io->writeln("  ⚙ php webrium $cmd");
                    }
                }
            }
        }

        // 9. Checksum
        $io->section('Integrity');
        $this->printHash($zipPath, $io);

        $this->cleanupTemp($tempDir, $zipPath, $source);
        return Command::SUCCESS;
    }
}
