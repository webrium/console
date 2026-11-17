<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webrium\App;
use Webrium\Console\Plugin\PluginConfigCompile;
use Webrium\Console\Plugin\PluginFilesMerge;
use Webrium\Console\Plugin\PluginFilesSplit;
use Webrium\Console\Plugin\PluginInstall;
use Webrium\Console\Plugin\PluginList;
use Webrium\Console\Plugin\PluginRemove;
use Webrium\Console\Plugin\PluginUpdate;
use Webrium\Directory;

/**
 * The `files` list of an installed plugin can live inline in plugins.json
 * (default, unchanged) or in storage/app/plugins/files/{name}.json, referenced
 * by `files_path`. These tests cover both layouts and the switch between them.
 */
class PluginFilesStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webrium_files_storage_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        App::initialize($this->tmpDir);
        Directory::initDefaultStructure();

        foreach (['app/Controllers', 'app/Models', 'app/Routes', 'app/Config', 'storage/logs', 'storage/app'] as $dir) {
            mkdir($this->tmpDir . '/' . $dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testDefaultInstallKeepsFilesInline(): void
    {
        $this->install('inline-plugin', '1.0.0');

        $entry = $this->entry('inline-plugin');
        $this->assertSame(['app/Controllers/DemoController.php'], $entry['files']);
        $this->assertArrayNotHasKey('files_path', $entry);
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/storage/app/plugins/files');
    }

    public function testSplitInstallStoresListInSeparateFile(): void
    {
        $this->useSplitMode();
        $this->install('split-plugin', '1.0.0');

        $entry = $this->entry('split-plugin');
        $this->assertArrayNotHasKey('files', $entry);
        $this->assertSame('storage/app/plugins/files/split-plugin.json', $entry['files_path']);
        $this->assertSame(
            ['app/Controllers/DemoController.php'],
            json_decode((string) file_get_contents($this->tmpDir . '/' . $entry['files_path']), true)
        );
    }

    public function testSplitEntryKeepsFilesKeyPositionBeforeMeta(): void
    {
        $this->useSplitMode();
        $this->install('order-plugin', '1.0.0');

        $keys = array_keys($this->entry('order-plugin'));
        $this->assertSame(count($keys) - 1, array_search('meta', $keys, true));
        $this->assertSame(count($keys) - 2, array_search('files_path', $keys, true));
    }

    public function testListShowsFileCountForSplitPlugins(): void
    {
        $this->useSplitMode();
        $this->install('list-plugin', '1.0.0');

        $tester = $this->tester(new PluginList());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/list-plugin\s*\|\s*1\.0\.0\s*\|[^|]*\|\s*1\s*\|/', $tester->getDisplay());
    }

    public function testSplitUpdateRemovesObsoleteFilesAndRewritesList(): void
    {
        $this->useSplitMode();
        $this->install('upd-plugin', '1.0.0', [['src' => 'app/Controllers/OldController.php', 'dest' => 'controllers', 'content' => '<?php // old']]);
        $this->assertFileExists($this->tmpDir . '/app/Controllers/OldController.php');

        $zip = $this->buildZip('upd-plugin', '2.0.0', [['src' => 'app/Controllers/NewController.php', 'dest' => 'controllers', 'content' => '<?php // new']]);
        $tester = $this->tester(new PluginUpdate());
        $tester->execute(['source' => $zip, '--no-backup' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/app/Controllers/OldController.php');
        $this->assertFileExists($this->tmpDir . '/app/Controllers/NewController.php');

        $entry = $this->entry('upd-plugin');
        $this->assertArrayNotHasKey('files', $entry);
        $this->assertSame(['app/Controllers/NewController.php'], json_decode((string) file_get_contents($this->tmpDir . '/' . $entry['files_path']), true));
    }

    public function testSplitRemoveDeletesPluginFilesAndTheListFile(): void
    {
        $this->useSplitMode();
        $this->install('rm-plugin', '1.0.0');
        $listFile = $this->tmpDir . '/storage/app/plugins/files/rm-plugin.json';
        $this->assertFileExists($listFile);

        $tester = $this->tester(new PluginRemove());
        $tester->setInputs(['yes']);
        $tester->execute(['name' => 'rm-plugin', '--no-backup' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tmpDir . '/app/Controllers/DemoController.php');
        $this->assertFileDoesNotExist($listFile);
        $this->assertSame([], $this->readRegistry()['installed']);
    }

    public function testUpdateAndRemoveStopWhenTheListFileIsMissing(): void
    {
        $this->useSplitMode();
        $this->install('lost-plugin', '1.0.0');
        unlink($this->tmpDir . '/storage/app/plugins/files/lost-plugin.json');

        $zip = $this->buildZip('lost-plugin', '2.0.0');
        $update = $this->tester(new PluginUpdate());
        $update->execute(['source' => $zip, '--no-backup' => true]);
        $this->assertSame(1, $update->getStatusCode());
        $this->assertStringContainsString('missing or unreadable', $update->getDisplay());
        $this->assertFileExists($this->tmpDir . '/app/Controllers/DemoController.php');

        $remove = $this->tester(new PluginRemove());
        $remove->setInputs(['yes']);
        $remove->execute(['name' => 'lost-plugin', '--no-backup' => true]);
        $this->assertSame(1, $remove->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Controllers/DemoController.php');
    }

    public function testUpdateCanSwitchAnInlinePluginToSplitAndBack(): void
    {
        $this->install('switch-plugin', '1.0.0');
        $this->assertArrayHasKey('files', $this->entry('switch-plugin'));

        $this->useSplitMode();
        $this->update('switch-plugin', '1.1.0');
        $entry = $this->entry('switch-plugin');
        $this->assertArrayNotHasKey('files', $entry);
        $this->assertFileExists($this->tmpDir . '/' . $entry['files_path']);

        $this->useInlineMode();
        $this->update('switch-plugin', '1.2.0');
        $entry = $this->entry('switch-plugin');
        $this->assertArrayNotHasKey('files_path', $entry);
        $this->assertSame(['app/Controllers/DemoController.php'], $entry['files']);
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/app/plugins/files/switch-plugin.json');
    }

    public function testCompileInlinesTheSplitListAndKeepsRegistryUntouched(): void
    {
        $this->useSplitMode();
        $this->install('compile-plugin', '1.0.0');

        $tester = $this->tester(new PluginConfigCompile());
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $compiled = json_decode((string) file_get_contents($this->tmpDir . '/storage/framework/cache/plugins.compiled.json'), true);
        $this->assertSame(['app/Controllers/DemoController.php'], $compiled['installed'][0]['files']);
        $this->assertArrayNotHasKey('files_path', $compiled['installed'][0]);
        $this->assertArrayHasKey('files_path', $this->entry('compile-plugin'));
    }

    public function testCompileFailsWhenAListFileIsMissing(): void
    {
        $this->useSplitMode();
        $this->install('gone-plugin', '1.0.0');
        unlink($this->tmpDir . '/storage/app/plugins/files/gone-plugin.json');

        $tester = $this->tester(new PluginConfigCompile());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString("plugin 'gone-plugin'", $tester->getDisplay());
    }

    public function testFilesPathOutsideTheProjectIsRefused(): void
    {
        $this->install('evil-plugin', '1.0.0');
        $registry = $this->readRegistry();
        unset($registry['installed'][0]['files']);
        $registry['installed'][0]['files_path'] = '../outside.json';
        $this->writeRegistry($registry);

        $tester = $this->tester(new PluginRemove());
        $tester->setInputs(['yes']);
        $tester->execute(['name' => 'evil-plugin', '--no-backup' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testSplitAndMergeCommandsConvertExistingRegistry(): void
    {
        $this->install('conv-a', '1.0.0');
        $this->install('conv-b', '1.0.0', [['src' => 'app/Controllers/BController.php', 'dest' => 'controllers', 'content' => '<?php']]);

        $dry = $this->tester(new PluginFilesSplit());
        $dry->execute(['--dry-run' => true]);
        $this->assertStringContainsString('would convert', $dry->getDisplay());
        $this->assertArrayHasKey('files', $this->entry('conv-a'));

        $split = $this->tester(new PluginFilesSplit());
        $split->execute([]);
        $this->assertSame(0, $split->getStatusCode(), $split->getDisplay());
        foreach (['conv-a', 'conv-b'] as $name) {
            $entry = $this->entry($name);
            $this->assertArrayNotHasKey('files', $entry);
            $this->assertFileExists($this->tmpDir . '/' . $entry['files_path']);
        }

        $again = $this->tester(new PluginFilesSplit());
        $again->execute([]);
        $this->assertStringContainsString('Nothing to convert', $again->getDisplay());

        $merge = $this->tester(new PluginFilesMerge());
        $merge->execute([]);
        $this->assertSame(0, $merge->getStatusCode(), $merge->getDisplay());
        $this->assertSame(['app/Controllers/BController.php'], $this->entry('conv-b')['files']);
        $this->assertArrayNotHasKey('files_path', $this->entry('conv-b'));
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/app/plugins/files/conv-a.json');
    }

    public function testInvalidFilesStorageValueIsRejected(): void
    {
        file_put_contents($this->tmpDir . '/.webrium.conf.json', json_encode(['console' => ['files_storage' => 'weird']]));

        $tester = $this->tester(new PluginList());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('files_storage', $tester->getDisplay());
    }

    // ---------------------------------------------------------------------

    private function useSplitMode(): void
    {
        file_put_contents($this->tmpDir . '/.webrium.conf.json', json_encode(['console' => ['files_storage' => 'split']]));
    }

    private function useInlineMode(): void
    {
        file_put_contents($this->tmpDir . '/.webrium.conf.json', json_encode(['console' => ['files_storage' => 'inline']]));
    }

    private function install(string $name, string $version, ?array $files = null): void
    {
        $zip = $this->buildZip($name, $version, $files);
        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zip]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function update(string $name, string $version): void
    {
        $zip = $this->buildZip($name, $version);
        $tester = $this->tester(new PluginUpdate());
        $tester->execute(['source' => $zip, '--no-backup' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function buildZip(string $name, string $version, ?array $files = null): string
    {
        $files ??= [['src' => 'app/Controllers/DemoController.php', 'dest' => 'controllers', 'content' => '<?php // demo']];

        $tempDir = $this->tmpDir . '/zip_build_' . uniqid();
        mkdir($tempDir . '/src', 0755, true);

        $manifestFiles = [];
        foreach ($files as $file) {
            $path = $tempDir . '/src/' . $file['src'];
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $file['content']);
            $manifestFiles[] = ['src' => $file['src'], 'dest' => $file['dest'], 'subpath' => null, 'overwrite' => true];
        }

        file_put_contents($tempDir . '/plugin.json', json_encode([
            'name' => $name,
            'version' => $version,
            'files' => $manifestFiles,
            'meta' => ['type' => 'test'],
        ]));

        $zipPath = $this->tmpDir . "/{$name}-{$version}.zip";
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($tempDir . '/plugin.json', 'plugin.json');
        foreach ($files as $file) {
            $zip->addFile($tempDir . '/src/' . $file['src'], 'src/' . $file['src']);
        }
        $zip->close();
        $this->removeDir($tempDir);

        return $zipPath;
    }

    private function tester(object $command): CommandTester
    {
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    private function readRegistry(): array
    {
        $path = $this->tmpDir . '/storage/app/plugins/plugins.json';

        return is_file($path) ? json_decode((string) file_get_contents($path), true) : ['installed' => []];
    }

    private function writeRegistry(array $data): void
    {
        file_put_contents($this->tmpDir . '/storage/app/plugins/plugins.json', json_encode($data));
    }

    private function entry(string $name): array
    {
        foreach ($this->readRegistry()['installed'] as $plugin) {
            if ($plugin['name'] === $name) {
                return $plugin;
            }
        }

        $this->fail("Plugin '$name' is not in the registry.");
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
