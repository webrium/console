<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Webrium\App;
use Webrium\Directory;

use Webrium\Console\GenerateController;
use Webrium\Console\GenerateModel;
use Webrium\Console\GenerateRoute;
use Webrium\Console\InitWebrium;
use Webrium\Console\LogAction;
use Webrium\Console\Plugin\PluginHelper;
use Webrium\Console\Plugin\PluginNew;
use Webrium\Console\Plugin\PluginList;
use Webrium\Console\Plugin\PluginInstall;
use Webrium\Console\Plugin\PluginRemove;
use Webrium\Console\Plugin\PluginUpdate;
use Webrium\Console\Plugin\PluginExport;

/**
 * Unit tests for webrium/console commands
 *
 * §1  Bootstrap helpers
 * §2  InitWebrium (init)
 * §3  GenerateController (make:controller)
 * §4  GenerateModel (make:model)
 * §5  GenerateRoute (make:route)
 * §6  LogAction (log)
 * §7  PluginNew (plugin:new)
 * §8  PluginList (plugin:list)
 * §9  PluginInstall (plugin:install)
 * §10 PluginRemove (plugin:remove)
 * §11 PluginUpdate (plugin:update)
 * §12 PluginExport (plugin:export)
 * §13 PluginHelper trait — unit-level
 */
class ConsoleTest extends TestCase
{
    private string $tmpDir;

    // -------------------------------------------------------------------------
    // §1  Bootstrap
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        // پوشه موقت جداگانه برای هر تست
        $this->tmpDir = sys_get_temp_dir() . '/webrium_console_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // راه‌اندازی App و Directory با root جدید
        App::initialize($this->tmpDir);
        Directory::initDefaultStructure();

        // ساخت ساختار پوشه‌ها
        foreach ([
            'app/Controllers', 'app/Models', 'app/Routes',
            'app/Config', 'storage/Logs', 'storage/App',
        ] as $dir) {
            mkdir($this->tmpDir . '/' . $dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function tester(object $command): CommandTester
    {
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    // =========================================================================
    // §2  InitWebrium
    // =========================================================================

    public function testInitCreatesDirectoryStructure(): void
    {
        $tester = $this->tester(new InitWebrium());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Project structure created', $tester->getDisplay());
    }

    // =========================================================================
    // §3  GenerateController
    // =========================================================================

    public function testMakeControllerCreatesFile(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'User']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Controllers/UserController.php');
    }

    public function testMakeControllerAppendsControllerSuffix(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'Product']);

        $this->assertFileExists($this->tmpDir . '/app/Controllers/ProductController.php');
    }

    public function testMakeControllerDoesNotDuplicateSuffix(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'OrderController']);

        $this->assertFileExists($this->tmpDir . '/app/Controllers/OrderController.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/app/Controllers/OrderControllerController.php');
    }

    public function testMakeControllerContainsCorrectNamespace(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'Auth']);

        $content = file_get_contents($this->tmpDir . '/app/Controllers/AuthController.php');
        $this->assertStringContainsString('namespace App\\Controllers', $content);
        $this->assertStringContainsString('class AuthController', $content);
    }

    public function testMakeControllerCustomNamespace(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'Api', '--namespace' => 'App\\Http\\Controllers']);

        $content = file_get_contents($this->tmpDir . '/app/Controllers/ApiController.php');
        $this->assertStringContainsString('namespace App\\Http\\Controllers', $content);
    }

    public function testMakeControllerFailsOnInvalidName(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => '123invalid']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid controller name', $tester->getDisplay());
    }

    public function testMakeControllerFailsIfFileExistsWithoutForce(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'User']);
        $tester->execute(['name' => 'User']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMakeControllerOverwritesWithForce(): void
    {
        $tester = $this->tester(new GenerateController());
        $tester->execute(['name' => 'User']);

        file_put_contents(
            $this->tmpDir . '/app/Controllers/UserController.php',
            '<?php // old content'
        );

        $tester->execute(['name' => 'User', '--force' => true]);
        $this->assertSame(0, $tester->getStatusCode());

        $content = file_get_contents($this->tmpDir . '/app/Controllers/UserController.php');
        $this->assertStringContainsString('class UserController', $content);
    }

    // =========================================================================
    // §4  GenerateModel
    // =========================================================================

    public function testMakeModelCreatesSimpleModel(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Post']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Models/Post.php');
    }

    public function testMakeModelWithTableOptionCreatesDbModel(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Post', '--table' => 'posts']);

        $content = file_get_contents($this->tmpDir . '/app/Models/Post.php');
        $this->assertStringContainsString('posts', $content);
    }

    public function testMakeModelAutoPluralizesTableName(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Comment', '--table' => '']);

        $content = file_get_contents($this->tmpDir . '/app/Models/Comment.php');
        $this->assertStringContainsString('comments', $content);
    }

    public function testMakeModelNoPluralOption(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Status', '--table' => '', '--no-plural' => true]);

        $content = file_get_contents($this->tmpDir . '/app/Models/Status.php');
        $this->assertStringContainsString('status', $content);
        $this->assertStringNotContainsString('statuss', $content);
    }

    public function testMakeModelConvertsToSnakeCase(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'UserPayment', '--table' => '']);

        $content = file_get_contents($this->tmpDir . '/app/Models/UserPayment.php');
        $this->assertStringContainsString('user_payments', $content);
    }

    public function testMakeModelFailsOnInvalidName(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => '1invalid']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid model name', $tester->getDisplay());
    }

    public function testMakeModelFailsIfExistsWithoutForce(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Post']);
        $tester->execute(['name' => 'Post']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMakeModelOverwritesWithForce(): void
    {
        $tester = $this->tester(new GenerateModel());
        $tester->execute(['name' => 'Post']);
        $tester->execute(['name' => 'Post', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    // =========================================================================
    // §5  GenerateRoute
    // =========================================================================

    public function testMakeRouteCreatesFile(): void
    {
        $tester = $this->tester(new GenerateRoute());
        $tester->execute(['name' => 'api']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Routes/api.php');
    }

    public function testMakeRouteFailsOnInvalidName(): void
    {
        $tester = $this->tester(new GenerateRoute());
        $tester->execute(['name' => 'my-route']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid route name', $tester->getDisplay());
    }

    public function testMakeRouteFailsIfExistsWithoutForce(): void
    {
        $tester = $this->tester(new GenerateRoute());
        $tester->execute(['name' => 'web']);
        $tester->execute(['name' => 'web']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMakeRouteOverwritesWithForce(): void
    {
        $tester = $this->tester(new GenerateRoute());
        $tester->execute(['name' => 'web']);
        $tester->execute(['name' => 'web', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    // =========================================================================
    // §6  LogAction
    // =========================================================================

    public function testLogListShowsAvailableLogs(): void
    {
        file_put_contents($this->tmpDir . '/storage/Logs/error_2026_06_01.txt', 'some error');

        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'list']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('error_2026_06_01.txt', $tester->getDisplay());
    }

    public function testLogListEmptyWhenNoLogs(): void
    {
        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'list']);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testLogClearRemovesLogFiles(): void
    {
        file_put_contents($this->tmpDir . '/storage/Logs/error_old.txt', 'error');

        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'clear']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/Logs/error_old.txt');
    }

    public function testLogLatestShowsMostRecentFile(): void
    {
        file_put_contents($this->tmpDir . '/storage/Logs/error_2026_06_15.txt', '##Error A#line1##Error B#line2');

        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'latest']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Error', $tester->getDisplay());
    }

    public function testLogLatestNoFilesPrintsMessage(): void
    {
        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'latest']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testLogFileShowsSpecificFile(): void
    {
        file_put_contents($this->tmpDir . '/storage/Logs/specific.txt', '##Error X#details');

        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'file', 'name' => 'specific.txt']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Error X', $tester->getDisplay());
    }

    public function testLogFileReturnsInvalidForMissingFile(): void
    {
        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'file', 'name' => 'nonexistent.txt']);

        $this->assertSame(2, $tester->getStatusCode()); // Command::INVALID
    }

    public function testLogInvalidActionReturnsInvalid(): void
    {
        $tester = $this->tester(new LogAction());
        $tester->execute(['action' => 'unknown']);

        $this->assertSame(2, $tester->getStatusCode()); // Command::INVALID
    }

    // =========================================================================
    // §7  PluginNew
    // =========================================================================

    public function testPluginNewCreatesDefinitionFile(): void
    {
        $tester = $this->tester(new PluginNew());
        $tester->execute(['name' => 'my-plugin']);

        $this->assertSame(0, $tester->getStatusCode());

        $defPath = $this->tmpDir . '/storage/App/plugins/definitions/my-plugin.json';
        $this->assertFileExists($defPath);

        $data = json_decode(file_get_contents($defPath), true);
        $this->assertSame('my-plugin', $data['name']);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('export', $data);
        $this->assertArrayHasKey('hooks', $data);
    }

    public function testPluginNewFailsOnInvalidName(): void
    {
        $tester = $this->tester(new PluginNew());
        $tester->execute(['name' => 'My Plugin!']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid plugin name', $tester->getDisplay());
    }

    public function testPluginNewFailsIfExistsWithoutForce(): void
    {
        $tester = $this->tester(new PluginNew());
        $tester->execute(['name' => 'my-plugin']);
        $tester->execute(['name' => 'my-plugin']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testPluginNewOverwritesWithForce(): void
    {
        $tester = $this->tester(new PluginNew());
        $tester->execute(['name' => 'my-plugin']);
        $tester->execute(['name' => 'my-plugin', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    // =========================================================================
    // §8  PluginList
    // =========================================================================

    public function testPluginListShowsInstalledPlugins(): void
    {
        $this->writeRegistry([
            'installed' => [
                [
                    'name'         => 'admin-panel',
                    'version'      => '1.0.0',
                    'author'       => 'Benjamin',
                    'files'        => ['app/Controllers/AdminController.php'],
                    'installed_at' => '2026-06-15 10:00:00',
                ],
            ],
        ]);

        $tester = $this->tester(new PluginList());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('admin-panel', $tester->getDisplay());
        $this->assertStringContainsString('1.0.0', $tester->getDisplay());
    }

    public function testPluginListShowsMessageWhenEmpty(): void
    {
        $tester = $this->tester(new PluginList());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No plugins installed', $tester->getDisplay());
    }

    // =========================================================================
    // §9  PluginInstall
    // =========================================================================

    public function testPluginInstallFailsForNonExistentFile(): void
    {
        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => '/nonexistent/path/plugin.zip']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testPluginInstallFailsForHttpUrl(): void
    {
        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => 'http://example.com/plugin.zip']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('HTTPS', $tester->getDisplay());
    }

    public function testPluginInstallSucceedsWithValidZip(): void
    {
        $zipPath = $this->buildValidPluginZip('test-plugin', '1.0.0');

        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zipPath]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('test-plugin', $tester->getDisplay());
        $this->assertStringContainsString('installed successfully', $tester->getDisplay());

        // فایل نصب‌شده باید وجود داشته باشد
        $this->assertFileExists($this->tmpDir . '/app/Controllers/DemoController.php');

        unlink($zipPath);
    }

    public function testPluginInstallRegistersPlugin(): void
    {
        $zipPath = $this->buildValidPluginZip('reg-plugin', '2.0.0');

        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zipPath]);

        $registry = $this->readRegistry();
        $found = array_filter($registry['installed'], fn($p) => $p['name'] === 'reg-plugin');
        $this->assertCount(1, $found);
        $this->assertSame('2.0.0', array_values($found)[0]['version']);

        unlink($zipPath);
    }

    public function testPluginInstallFailsIfAlreadyInstalled(): void
    {
        $zipPath = $this->buildValidPluginZip('dup-plugin', '1.0.0');

        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zipPath]);
        $tester->execute(['source' => $zipPath]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already installed', $tester->getDisplay());

        unlink($zipPath);
    }

    public function testPluginInstallFailsOnConflictWithoutForce(): void
    {
        $zipPath = $this->buildValidPluginZip('conflict-plugin', '1.0.0');

        // فایل مقصد از قبل وجود دارد
        file_put_contents($this->tmpDir . '/app/Controllers/DemoController.php', '<?php // existing');

        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zipPath]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exist', $tester->getDisplay());

        unlink($zipPath);
    }

    public function testPluginInstallDryRunDoesNotWriteFiles(): void
    {
        $zipPath = $this->buildValidPluginZip('dry-plugin', '1.0.0');

        $tester = $this->tester(new PluginInstall());
        $tester->execute(['source' => $zipPath, '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->tmpDir . '/app/Controllers/DemoController.php');

        unlink($zipPath);
    }

    // =========================================================================
    // §10 PluginRemove
    // =========================================================================

    public function testPluginRemoveFailsIfNotInstalled(): void
    {
        $tester = $this->tester(new PluginRemove());
        $tester->execute(['name' => 'nonexistent'], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not installed', $tester->getDisplay());
    }

    public function testPluginRemoveDeletesFilesAndRegistry(): void
    {
        // نصب اول
        $zipPath = $this->buildValidPluginZip('rm-plugin', '1.0.0');
        $installer = $this->tester(new PluginInstall());
        $installer->execute(['source' => $zipPath]);
        unlink($zipPath);

        $this->assertFileExists($this->tmpDir . '/app/Controllers/DemoController.php');

        // حذف
        $tester = $this->tester(new PluginRemove());
        $tester->setInputs(['yes']);
        $tester->execute(['name' => 'rm-plugin', '--no-backup' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->tmpDir . '/app/Controllers/DemoController.php');

        $registry = $this->readRegistry();
        $found = array_filter($registry['installed'], fn($p) => $p['name'] === 'rm-plugin');
        $this->assertCount(0, $found);
    }

    public function testPluginRemoveKeepFilesOnlyUpdatesRegistry(): void
    {
        $zipPath = $this->buildValidPluginZip('keep-plugin', '1.0.0');
        $installer = $this->tester(new PluginInstall());
        $installer->execute(['source' => $zipPath]);
        unlink($zipPath);

        $tester = $this->tester(new PluginRemove());
        $tester->setInputs(['yes']);
        $tester->execute(['name' => 'keep-plugin', '--keep-files' => true, '--no-backup' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        // فایل باید هنوز وجود داشته باشد
        $this->assertFileExists($this->tmpDir . '/app/Controllers/DemoController.php');

        // از registry حذف شده باشد
        $registry = $this->readRegistry();
        $found = array_filter($registry['installed'], fn($p) => $p['name'] === 'keep-plugin');
        $this->assertCount(0, $found);
    }

    // =========================================================================
    // §11 PluginUpdate
    // =========================================================================

    public function testPluginUpdateFailsIfNotInstalled(): void
    {
        $zipPath = $this->buildValidPluginZip('not-installed', '2.0.0');

        $tester = $this->tester(new PluginUpdate());
        $tester->execute(['source' => $zipPath]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not installed', $tester->getDisplay());

        unlink($zipPath);
    }

    public function testPluginUpdateFailsIfVersionNotNewer(): void
    {
        $zipPath = $this->buildValidPluginZip('upd-plugin', '1.0.0');
        $installer = $this->tester(new PluginInstall());
        $installer->execute(['source' => $zipPath]);
        unlink($zipPath);

        // همان نسخه را دوباره update می‌کنیم (بدون --force)
        $sameZip = $this->buildValidPluginZip('upd-plugin', '1.0.0', 'v2content');
        $tester = $this->tester(new PluginUpdate());
        $tester->execute(['source' => $sameZip]);

        $this->assertSame(1, $tester->getStatusCode());

        unlink($sameZip);
    }

    public function testPluginUpdateSucceedsWithNewerVersion(): void
    {
        // نصب نسخه 1.0.0
        $zipV1 = $this->buildValidPluginZip('upd2-plugin', '1.0.0');
        $installer = $this->tester(new PluginInstall());
        $installer->execute(['source' => $zipV1]);
        unlink($zipV1);

        // آپدیت به نسخه 2.0.0
        $zipV2 = $this->buildValidPluginZip('upd2-plugin', '2.0.0', 'newcontent');
        $tester = $this->tester(new PluginUpdate());
        $tester->execute(['source' => $zipV2, '--no-backup' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('updated to v2.0.0', $tester->getDisplay());

        $registry = $this->readRegistry();
        $found = array_values(array_filter($registry['installed'], fn($p) => $p['name'] === 'upd2-plugin'));
        $this->assertSame('2.0.0', $found[0]['version']);

        unlink($zipV2);
    }

    // =========================================================================
    // §12 PluginExport
    // =========================================================================

    public function testPluginExportFailsIfDefinitionMissing(): void
    {
        $tester = $this->tester(new PluginExport());
        $tester->execute(['name' => 'nonexistent', 'version' => '1.0.0']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testPluginExportFailsOnInvalidVersion(): void
    {
        // ابتدا definition می‌سازیم
        $this->tester(new PluginNew())->execute(['name' => 'exp-plugin']);

        $tester = $this->tester(new PluginExport());
        $tester->execute(['name' => 'exp-plugin', 'version' => 'not-semver']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid version', $tester->getDisplay());
    }

    public function testPluginExportDryRunDoesNotCreateZip(): void
    {
        // definition با فایل واقعی
        $this->createExportDefinition('exp2-plugin', 'AdminController');

        $tester = $this->tester(new PluginExport());
        $tester->execute(['name' => 'exp2-plugin', 'version' => '1.0.0', '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $zipPath = $this->tmpDir . '/storage/App/plugins/dist/exp2-plugin-v1.0.0.zip';
        $this->assertFileDoesNotExist($zipPath);
    }

    public function testPluginExportCreatesZipFile(): void
    {
        $this->createExportDefinition('exp3-plugin', 'SomeController');

        $tester = $this->tester(new PluginExport());
        $tester->execute(['name' => 'exp3-plugin', 'version' => '1.5.0']);

        $this->assertSame(0, $tester->getStatusCode());

        $zipPath = $this->tmpDir . '/storage/App/plugins/dist/exp3-plugin-v1.5.0.zip';
        $this->assertFileExists($zipPath);

        // بررسی که zip معتبر است
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $zip->close();
    }

    // =========================================================================
    // §13 PluginHelper trait — unit-level
    // =========================================================================

    public function testReadManifestFailsIfPluginJsonMissing(): void
    {
        $helper = $this->makeHelperInstance();
        $dir    = $this->tmpDir . '/empty_zip_dir';
        mkdir($dir);

        $io = $this->makeNullIo();
        $result = $this->callHelperMethod($helper, 'readManifest', [$dir, $io]);

        $this->assertNull($result);
    }

    public function testReadManifestFailsIfRequiredFieldMissing(): void
    {
        $helper = $this->makeHelperInstance();
        $dir    = $this->tmpDir . '/bad_manifest';
        mkdir($dir);
        file_put_contents($dir . '/plugin.json', json_encode(['name' => 'test']));

        $io     = $this->makeNullIo();
        $result = $this->callHelperMethod($helper, 'readManifest', [$dir, $io]);

        $this->assertNull($result);
    }

    public function testReadManifestSucceedsWithValidData(): void
    {
        $helper = $this->makeHelperInstance();
        $dir    = $this->tmpDir . '/good_manifest';
        mkdir($dir);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name'    => 'my-plugin',
            'version' => '1.0.0',
            'files'   => [],
        ]));

        $io     = $this->makeNullIo();
        $result = $this->callHelperMethod($helper, 'readManifest', [$dir, $io]);

        $this->assertIsArray($result);
        $this->assertSame('my-plugin', $result['name']);
    }

    public function testReadManifestFailsOnInvalidPluginName(): void
    {
        $helper = $this->makeHelperInstance();
        $dir    = $this->tmpDir . '/bad_name';
        mkdir($dir);
        file_put_contents($dir . '/plugin.json', json_encode([
            'name'    => 'My Plugin!',
            'version' => '1.0.0',
            'files'   => [],
        ]));

        $io     = $this->makeNullIo();
        $result = $this->callHelperMethod($helper, 'readManifest', [$dir, $io]);

        $this->assertNull($result);
    }

    public function testValidateZipFailsForNonExistentFile(): void
    {
        $helper = $this->makeHelperInstance();
        $io     = $this->makeNullIo();
        $result = $this->callHelperMethod($helper, 'validateZip', ['/nonexistent.zip', $io]);

        $this->assertFalse($result);
    }

    public function testValidateZipFailsForInvalidZip(): void
    {
        $helper  = $this->makeHelperInstance();
        $io      = $this->makeNullIo();
        $badZip  = $this->tmpDir . '/bad.zip';
        file_put_contents($badZip, 'this is not a zip file');

        $result = $this->callHelperMethod($helper, 'validateZip', [$badZip, $io]);

        $this->assertFalse($result);
    }

    public function testValidateZipSucceedsForValidZip(): void
    {
        $helper  = $this->makeHelperInstance();
        $io      = $this->makeNullIo();
        $zipPath = $this->buildMinimalZip();

        $result = $this->callHelperMethod($helper, 'validateZip', [$zipPath, $io]);

        $this->assertTrue($result);
        unlink($zipPath);
    }

    public function testRegistryReadReturnsEmptyWhenNoFile(): void
    {
        $helper = $this->makeHelperInstance();
        $result = $this->callHelperMethod($helper, 'readRegistry', []);

        $this->assertArrayHasKey('installed', $result);
        $this->assertEmpty($result['installed']);
    }

    public function testFindInRegistryReturnsNullWhenNotFound(): void
    {
        $helper   = $this->makeHelperInstance();
        $registry = ['installed' => [['name' => 'other', 'version' => '1.0.0']]];
        $result   = $this->callHelperMethod($helper, 'findInRegistry', [$registry, 'missing']);

        $this->assertNull($result);
    }

    public function testFindInRegistryReturnsPluginWhenFound(): void
    {
        $helper   = $this->makeHelperInstance();
        $entry    = ['name' => 'found-plugin', 'version' => '1.0.0'];
        $registry = ['installed' => [$entry]];
        $result   = $this->callHelperMethod($helper, 'findInRegistry', [$registry, 'found-plugin']);

        $this->assertSame('found-plugin', $result['name']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * یک zip معتبر با plugin.json و یک فایل controller می‌سازد
     */
    private function buildValidPluginZip(string $name, string $version, string $fileContent = '<?php // demo'): string
    {
        $tempDir = $this->tmpDir . '/zip_build_' . uniqid();
        mkdir($tempDir . '/src/app/Controllers', 0755, true);

        file_put_contents($tempDir . '/src/app/Controllers/DemoController.php', $fileContent);

        $manifest = [
            'name'    => $name,
            'version' => $version,
            'files'   => [
                [
                    'src'       => 'app/Controllers/DemoController.php',
                    'dest'      => 'controllers',
                    'overwrite' => false,
                ],
            ],
        ];
        file_put_contents($tempDir . '/plugin.json', json_encode($manifest));

        $zipPath = $this->tmpDir . "/{$name}-{$version}.zip";
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($tempDir . '/plugin.json', 'plugin.json');
        $zip->addFile(
            $tempDir . '/src/app/Controllers/DemoController.php',
            'src/app/Controllers/DemoController.php'
        );
        $zip->close();

        $this->removeDir($tempDir);
        return $zipPath;
    }

    /**
     * یک zip ساده بدون محتوای خاص برای تست validateZip
     */
    private function buildMinimalZip(): string
    {
        $zipPath = $this->tmpDir . '/minimal.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('dummy.txt', 'hello');
        $zip->close();
        return $zipPath;
    }

    /**
     * یک definition و فایل controller واقعی برای PluginExport می‌سازد
     */
    private function createExportDefinition(string $pluginName, string $controllerName): void
    {
        // ساخت definition
        $this->tester(new PluginNew())->execute(['name' => $pluginName]);

        $controllerFile = $this->tmpDir . "/app/Controllers/{$controllerName}.php";
        file_put_contents($controllerFile, "<?php class $controllerName {}");

        $defPath = $this->tmpDir . "/storage/App/plugins/definitions/{$pluginName}.json";
        $def = json_decode(file_get_contents($defPath), true);
        $def['export'] = [
            [
                'file'      => "app/Controllers/{$controllerName}.php",
                'dest'      => 'controllers',
                'subpath'   => null,
                'overwrite' => false,
            ],
        ];
        file_put_contents($defPath, json_encode($def));
    }

    /**
     * registry را در مسیر پیش‌فرض می‌نویسد
     */
    private function writeRegistry(array $data): void
    {
        $dir = $this->tmpDir . '/storage/App/plugins';
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/plugins.json', json_encode($data));
    }

    /**
     * registry را می‌خواند
     */
    private function readRegistry(): array
    {
        $path = $this->tmpDir . '/storage/App/plugins/plugins.json';
        if (!file_exists($path)) return ['installed' => []];
        return json_decode(file_get_contents($path), true);
    }

    /**
     * یک instance ناشناس از PluginHelper می‌سازد
     */
    private function makeHelperInstance(): object
    {
        return new class extends \Symfony\Component\Console\Command\Command {
            use PluginHelper;
            protected static $defaultName = 'test:helper';
            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int { return 0; }
        };
    }

    /**
     * یک SymfonyStyle بی‌صدا برای تست متدهای trait می‌سازد
     */
    private function makeNullIo(): \Symfony\Component\Console\Style\SymfonyStyle
    {
        return new \Symfony\Component\Console\Style\SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
    }

    /**
     * یک متد private/protected از helper را با Reflection صدا می‌زند
     */
    private function callHelperMethod(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }
}
