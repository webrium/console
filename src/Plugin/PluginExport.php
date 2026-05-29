<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

class PluginExport extends Command
{
    use PluginHelper;

    protected static $defaultName        = 'plugin:export';
    protected static $defaultDescription = 'Export a plugin zip from a project definition file';

    protected function configure()
    {
        Directory::initDefaultStructure();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Plugin definition name (without .json)')
            ->addArgument('version', InputArgument::REQUIRED, 'Version to set (e.g. 1.2.0)')
            ->addOption('dry-run',  null, InputOption::VALUE_NONE,     'Preview without creating zip')
            ->addOption('force',    'f',  InputOption::VALUE_NONE,     'Overwrite existing zip if version already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $name   = $input->getArgument('name');
        $dryRun = $input->getOption('dry-run');
        $force  = $input->getOption('force');

        if ($dryRun) $io->note('Dry-run mode: no zip will be created.');

        // 1. Load definition
        $defPath = $this->definitionPath($name);
        if (!file_exists($defPath)) {
            $io->error("Definition file not found: $defPath");
            $io->writeln('Create it with: <fg=cyan>php webrium plugin:new ' . $name . '</>');
            return Command::FAILURE;
        }

        $def = json_decode(file_get_contents($defPath), true);
        if (!is_array($def)) {
            $io->error("Definition file is not valid JSON: $defPath");
            return Command::FAILURE;
        }

        foreach (['name', 'export'] as $field) {
            if (empty($def[$field])) {
                $io->error("Definition file missing required field: '$field'.");
                return Command::FAILURE;
            }
        }

        // 2. Resolve version
        $version = $input->getArgument('version');
        if (empty($version)) {
            $version = $io->ask(
                'Plugin version',
                $def['version'] ?? '1.0.0',
                function (string $v) {
                    if (!preg_match('/^\d+\.\d+\.\d+$/', $v)) {
                        throw new \RuntimeException("Version must be semantic (e.g. 1.0.0).");
                    }
                    return $v;
                }
            );
        }

        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $io->error("Invalid version '$version'. Use semantic versioning (e.g. 1.0.0).");
            return Command::FAILURE;
        }

        $def['version'] = $version;

        // 3. Resolve project root
        $projectRoot = realpath(Directory::path('app') . '/../') ?: getcwd();

        // 4. Validate all export files exist
        $io->title("Exporting: {$def['name']} v$version");
        $io->writeln('<fg=cyan>Files to include:</>');

        $missing = [];
        foreach ($def['export'] as $entry) {
            $abs = $projectRoot . '/' . ltrim($entry['file'], '/');
            $abs = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $abs);

            $exists = file_exists($abs);
            $tag    = $exists ? '<fg=green>✔</>' : '<fg=red>✖</>';
            $io->writeln("  $tag {$entry['file']}");

            if (!$exists) $missing[] = $entry['file'];
        }

        if (!empty($def['sql'])) {
            $io->writeln('<fg=cyan>SQL files to include:</>');
            foreach ($def['sql'] as $sqlFile) {
                $abs = $projectRoot . '/' . ltrim($sqlFile, '/');
                $abs = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $abs);

                $exists = file_exists($abs);
                $tag    = $exists ? '<fg=green>✔</>' : '<fg=red>✖</>';
                $io->writeln("  $tag $sqlFile");

                if (!$exists) $missing[] = $sqlFile;
            }
        }

        if (!empty($missing)) {
            $io->error('Cannot build: ' . count($missing) . ' file(s) not found.');
            return Command::FAILURE;
        }

        // 5. Check output path
        $distDir = Directory::path('storage_app') . '/plugins-dist';
        $zipName = "{$def['name']}-v{$version}.zip";
        $zipPath = $distDir . '/' . $zipName;

        if (file_exists($zipPath) && !$force) {
            $io->warning("'$zipName' already exists in plugins-dist. Use --force to overwrite.");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln("<fg=cyan>Output would be:</> $zipPath");
            $io->success('Dry-run complete. No files were written.');
            return Command::SUCCESS;
        }

        // 6. Build zip in temp
        $tempDir = sys_get_temp_dir() . '/webrium_export_' . uniqid();
        @mkdir($tempDir . '/src', 0755, true);
        @mkdir($distDir, 0755, true);

        foreach ($def['export'] as $entry) {
            $abs     = realpath($projectRoot . '/' . ltrim($entry['file'], '/'));
            $relPath = ltrim(str_replace(['/', '\\'], '/', $entry['file']), '/');
            $destPath = $tempDir . '/src/' . $relPath;
            @mkdir(dirname($destPath), 0755, true);
            copy($abs, $destPath);
        }

        // Copy SQL files into zip (preserving relative path)
        foreach ($def['sql'] ?? [] as $relativePath) {
            $abs = realpath($projectRoot . '/' . ltrim($relativePath, '/'));
            if (!$abs || !file_exists($abs)) {
                $io->error("SQL file not found: $relativePath");
                $this->deleteDir($tempDir);
                return Command::FAILURE;
            }
            $relPath  = ltrim(str_replace(['/', '\\'], '/', $relativePath), '/');
            $destPath = $tempDir . '/src/' . $relPath;
            @mkdir(dirname($destPath), 0755, true);
            copy($abs, $destPath);
        }

        // 7. Write plugin.json into zip root
        $manifest = $this->buildManifest($def);
        file_put_contents($tempDir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 8. Create zip
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $io->error("Failed to create zip at: $zipPath");
            $this->deleteDir($tempDir);
            return Command::FAILURE;
        }

        $this->addDirToZip($zip, $tempDir, $tempDir);
        $zip->close();
        $this->deleteDir($tempDir);

        // 9. Update version in definition file
        $def['version'] = $version;
        file_put_contents($defPath, json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 10. Show result
        $io->writeln('');
        $io->writeln("<fg=cyan>Output:</> $zipPath");
        $this->printHash($zipPath, $io);
        $io->success("Plugin '{$def['name']}' v$version exported successfully.");

        return Command::SUCCESS;
    }

    private function buildManifest(array $def): array
    {
        $files = array_map(fn($e) => [
            'src'       => ltrim(str_replace(['/', '\\'], '/', $e['file']), '/'),
            'dest'      => $e['dest'],
            'subpath'   => $e['subpath'] ?? null,
            'overwrite' => $e['overwrite'] ?? false,
        ], $def['export']);

        return [
            'name'        => $def['name'],
            'version'     => $def['version'],
            'description' => $def['description'] ?? '',
            'author'      => $def['author']      ?? '',
            'require'     => $def['require']      ?? [],
            'files'       => $files,
            'sql'         => $def['sql']          ?? [],
            'hooks'       => $def['hooks']        ?? ['before_install' => [], 'after_install' => []],
            'meta'        => $def['meta']         ?? [],
        ];
    }

    private function addDirToZip(\ZipArchive $zip, string $dir, string $base): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path    = $dir . DIRECTORY_SEPARATOR . $item;
            $zipPath = ltrim(str_replace($base, '', $path), DIRECTORY_SEPARATOR);
            if (is_dir($path)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirToZip($zip, $path, $base);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }

    private function definitionPath(string $name): string
    {
        return Directory::path('storage_app') . '/plugin-definitions/' . $name . '.json';
    }
}