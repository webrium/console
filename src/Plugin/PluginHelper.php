<?php
namespace Webrium\Console\Plugin;

use Symfony\Component\Console\Style\SymfonyStyle;
use Webrium\Directory;

trait PluginHelper
{
    private function resolveSource(string $source, SymfonyStyle $io): ?string
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            if (!str_starts_with($source, 'https://')) {
                $io->error('Only HTTPS URLs are allowed.');
                return null;
            }
            return $this->downloadZip($source, $io);
        }

        if (!file_exists($source)) {
            $io->error("File not found: '$source'");
            return null;
        }

        return $source;
    }

    private function downloadZip(string $url, SymfonyStyle $io): ?string
    {
        $io->writeln("<fg=cyan>⬇ Downloading:</> $url");

        $tempFile = sys_get_temp_dir() . '/webrium_dl_' . uniqid() . '.zip';
        $context  = stream_context_create([
            'http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'Webrium/Console'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            $io->error("Failed to download: $url");
            return null;
        }

        file_put_contents($tempFile, $data);
        $io->writeln('<fg=green>✔ Download complete.</>');
        return $tempFile;
    }

    private function validateZip(string $path, SymfonyStyle $io): bool
    {
        if (!file_exists($path)) {
            $io->error("Zip file not found: $path");
            return false;
        }

        if (filesize($path) > 100 * 1024 * 1024) {
            $io->error('Zip file exceeds the 100 MB size limit.');
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            $io->error('Not a valid zip archive.');
            return false;
        }

        if ($zip->count() > 1000) {
            $zip->close();
            $io->error('Zip contains too many files (limit: 1000).');
            return false;
        }

        $zip->close();
        return true;
    }

    private function extractZip(string $zipPath, string $destDir): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) return false;
        @mkdir($destDir, 0755, true);
        $zip->extractTo($destDir);
        $zip->close();
        return true;
    }

    private function readManifest(string $tempDir, SymfonyStyle $io): ?array
    {
        $jsonPath = $tempDir . '/plugin.json';

        if (!file_exists($jsonPath)) {
            $io->error('plugin.json not found in the archive root.');
            return null;
        }

        $data = json_decode(file_get_contents($jsonPath), true);

        if (!is_array($data)) {
            $io->error('plugin.json is not valid JSON.');
            return null;
        }

        foreach (['name', 'version', 'files'] as $field) {
            if (empty($data[$field])) {
                $io->error("plugin.json missing required field: '$field'.");
                return null;
            }
        }

        if (!preg_match('/^[a-z0-9\-_]+$/', $data['name'])) {
            $io->error("Invalid plugin name '{$data['name']}'. Use lowercase letters, numbers, hyphens, underscores only.");
            return null;
        }

        return $data;
    }

    private function buildPlan(array $manifest, string $tempDir, SymfonyStyle $io): ?array
    {
        $allowed     = ['php', 'html', 'htm', 'js', 'css', 'json', 'md', 'txt', 'svg', 'xml', 'vue'];
        $projectRoot = realpath(Directory::path('app') . '/../') ?: getcwd();
        $srcBase     = realpath($tempDir . '/src');
        $plan        = [];

        foreach ($manifest['files'] as $i => $entry) {
            if (empty($entry['src']) || empty($entry['dest'])) {
                $io->error("File entry #$i is missing 'src' or 'dest'.");
                return null;
            }

            $ext = strtolower(pathinfo($entry['src'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $io->error("Disallowed extension '.$ext' in '{$entry['src']}'.");
                return null;
            }

            $srcPath = realpath($tempDir . '/src/' . ltrim($entry['src'], '/'));
            if (!$srcPath || !file_exists($srcPath)) {
                $io->error("Source file not found in archive: src/{$entry['src']}");
                return null;
            }

            if (!str_starts_with($srcPath, $srcBase)) {
                $io->error("Path traversal detected in src: '{$entry['src']}'.");
                return null;
            }

            $destDir = Directory::path($entry['dest']);
            if (!$destDir) {
                $io->error("Unknown destination key '{$entry['dest']}'.");
                return null;
            }

            if (!empty($entry['subpath'])) {
                $sub     = preg_replace('/\.\.[\\/]/', '', $entry['subpath']);
                $sub     = ltrim($sub, '/\\');
                $destDir = $destDir . DIRECTORY_SEPARATOR . $sub;
            }

            $destPath     = $destDir . DIRECTORY_SEPARATOR . basename($entry['src']);
            $resolvedDest = realpath($destDir) ?: $destDir;

            if (realpath($projectRoot) && !str_starts_with($resolvedDest, realpath($projectRoot))) {
                $io->error("Path traversal detected in destination for '{$entry['src']}'.");
                return null;
            }

            $plan[] = [
                'src'       => $srcPath,
                'dest'      => $destPath,
                'dest_dir'  => $destDir,
                'overwrite' => $entry['overwrite'] ?? false,
            ];
        }

        return $plan;
    }

    private function runHooks(array $hooks, string $stage, SymfonyStyle $io): bool
    {
        if (empty($hooks[$stage])) return true;

        $allowed = ['make:controller', 'make:model', 'make:route', 'init'];

        foreach ($hooks[$stage] as $cmd) {
            $parts   = explode(' ', trim($cmd));
            $command = $parts[0] ?? '';

            if (!in_array($command, $allowed, true)) {
                $io->error("Hook command '$command' is not allowed. Allowed: " . implode(', ', $allowed));
                return false;
            }

            $io->writeln("<fg=cyan>⚙ Hook [$stage]:</> php webrium $cmd");

            $app = $this->getApplication();
            if ($app === null) {
                $io->error('Cannot access application for hook execution.');
                return false;
            }

            $args   = array_slice($parts, 1);
            $input  = new \Symfony\Component\Console\Input\StringInput($cmd);
            $result = $app->find($command)->run($input, new \Symfony\Component\Console\Output\NullOutput());

            if ($result !== \Symfony\Component\Console\Command\Command::SUCCESS) {
                $io->error("Hook command failed: php webrium $cmd");
                return false;
            }

            $io->writeln("<fg=green>✔ Hook done.</>");
        }

        return true;
    }

    private function readRegistry(): array
    {
        $path = $this->registryPath();
        if (!file_exists($path)) return ['installed' => []];
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : ['installed' => []];
    }

    private function saveRegistry(array $registry): void
    {
        file_put_contents(
            $this->registryPath(),
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function findInRegistry(array $registry, string $name): ?array
    {
        foreach ($registry['installed'] as $plugin) {
            if ($plugin['name'] === $name) return $plugin;
        }
        return null;
    }

    private function registryPath(): string
    {
        return Directory::path('storage_app') . '/plugins.json';
    }

    private function backupFiles(array $files, string $pluginName, string $tag, SymfonyStyle $io): void
    {
        $dir = Directory::path('storage_app') . '/plugin-backups/' . $pluginName . '_' . $tag . '_' . date('Ymd_His');
        @mkdir($dir, 0755, true);

        foreach ($files as $file) {
            if (file_exists($file)) {
                copy($file, $dir . '/' . basename($file) . '.bak');
            }
        }

        $io->writeln("<fg=yellow>⚠ Backup saved to:</> $dir");
    }

    private function cleanupTemp(string $tempDir, string $zipPath, string $source): void
    {
        $this->deleteDir($tempDir);
        if (filter_var($source, FILTER_VALIDATE_URL) && file_exists($zipPath)) {
            unlink($zipPath);
        }
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function printHash(string $zipPath, SymfonyStyle $io): void
    {
        $hash = hash_file('sha256', $zipPath);
        $io->writeln("<fg=cyan>SHA-256:</> $hash");
    }

    private function runSqlFiles(array $manifest, string $tempDir, SymfonyStyle $io): bool
    {
        if (empty($manifest['sql'])) return true;

        foreach ($manifest['sql'] as $relativePath) {
            $sqlPath = realpath($tempDir . '/src/' . ltrim($relativePath, '/'));

            if (!$sqlPath || !file_exists($sqlPath)) {
                $io->error("SQL file not found in archive: src/$relativePath");
                return false;
            }

            if (!str_starts_with($sqlPath, realpath($tempDir . '/src'))) {
                $io->error("Path traversal detected in sql entry: '$relativePath'.");
                return false;
            }

            $sql = file_get_contents($sqlPath);
            if (empty(trim($sql))) {
                $io->warning("SQL file is empty, skipping: $relativePath");
                continue;
            }

            $io->writeln("<fg=cyan>⚙ Running SQL:</> $relativePath");

            // Split into individual statements and execute each one
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => $s !== ''
            );

            try {
                foreach ($statements as $statement) {
                    \Foxdb\DB::statement($statement);
                }
                $io->writeln("<fg=green>✔ SQL executed:</> $relativePath");
            } catch (\Exception $e) {
                $io->error("SQL execution failed for '$relativePath': " . $e->getMessage());
                return false;
            }
        }

        return true;
    }
}