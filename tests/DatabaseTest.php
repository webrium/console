<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Webrium\App;
use Webrium\Directory;

use Webrium\Console\DbAction;
use Webrium\Console\TableAction;
use Webrium\Console\MigrateAction;
use Webrium\Console\GenerateMigration;

use Foxdb\DB;
use Foxdb\Schema;

/**
 * Database-related console command tests
 *
 * §1  Bootstrap helpers
 * §2  GenerateMigration (make:migration)
 * §3  MigrateAction (migrate)  — uses SQLite :memory:
 * §4  DbAction (db)             — uses MySQL when available
 * §5  TableAction (table)       — uses MySQL when available
 */
class DatabaseTest extends TestCase
{
    private string $tmpDir;

    // =========================================================================
    // §1  Bootstrap
    // =========================================================================

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webrium_db_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        App::initialize($this->tmpDir);
        Directory::initDefaultStructure();

        foreach ([
            'app/Controllers', 'app/Models', 'app/Routes',
            'app/Config', 'storage/logs', 'storage/app',
            'database/migrations', 'database/seeders',
        ] as $dir) {
            mkdir($this->tmpDir . '/' . $dir, 0755, true);
        }

        // Reset DB state between tests
        DB::reset();
    }

    protected function tearDown(): void
    {
        DB::reset();
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

    /**
     * Boot a fresh SQLite :memory: connection for migration/seeder tests.
     */
    private function bootSqlite(): void
    {
        DB::reset();
        DB::addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    /**
     * Attempt to boot a MySQL connection. Returns false when the server is
     * not reachable so tests can be marked skipped gracefully.
     */
    private function bootMysql(): bool
    {
        DB::reset();
        try {
            DB::addConnection([
                'driver'   => 'mysql',
                'host'     => '127.0.0.1',
                'port'     => '3306',
                'database' => 'mysql',  // built-in, always exists
                'username' => 'root',
                'password' => '123456',
                'charset'  => 'utf8mb4',
            ]);
            // Ping the server
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            DB::reset();
            return false;
        }
    }

    /**
     * Create a minimal migration PHP file and return its path.
     *
     * @param string $class  PHP class name
     * @param string $upSql  SQL for up()
     * @param string $downSql SQL for down()
     */
    private function writeMigrationFile(
        string $class,
        string $upSql   = '',
        string $downSql = ''
    ): string {
        $dir = $this->tmpDir . '/database/migrations';
        $timestamp = date('Y_m_d_His');
        $file = "{$dir}/{$timestamp}_{$class}.php";

        file_put_contents($file, <<<PHP
<?php
use Foxdb\Migrations\Migration;
use Foxdb\Schema;
use Foxdb\Schema\Blueprint;

class {$class} extends Migration
{
    public function up(): void
    {
        {$upSql}
    }

    public function down(): void
    {
        {$downSql}
    }
}
PHP);
        return $file;
    }

    // =========================================================================
    // §2  GenerateMigration (make:migration)
    // =========================================================================

    public function testMakeMigrationCreatesFile(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_users_table']);

        $this->assertSame(0, $tester->getStatusCode());

        $files = glob($this->tmpDir . '/database/migrations/*_create_users_table.php');
        $this->assertNotEmpty($files, 'Migration file was not created.');
    }

    public function testMakeMigrationContainsCorrectClass(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_posts_table']);

        $files = glob($this->tmpDir . '/database/migrations/*_create_posts_table.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('class CreatePostsTable', $content);
    }

    public function testMakeMigrationInfersTableNameFromConvention(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_orders_table']);

        $files = glob($this->tmpDir . '/database/migrations/*_create_orders_table.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        // The stub should contain the inferred table name
        $this->assertStringContainsString('orders', $content);
    }

    public function testMakeMigrationRespectsExplicitTableOption(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_users_table', '--table' => 'custom_users']);

        $files = glob($this->tmpDir . '/database/migrations/*_create_users_table.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('custom_users', $content);
    }

    public function testMakeMigrationUsesUpdateStubForAlterMigrations(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'add_email_to_users_table']);

        $files = glob($this->tmpDir . '/database/migrations/*_add_email_to_users_table.php');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        // Update stub should reference the table name found after "to_"
        $this->assertStringContainsString('users', $content);
    }

    public function testMakeMigrationFailsOnInvalidName(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => '1invalid-name']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid migration name', $tester->getDisplay());
    }

    public function testMakeMigrationFailsWhenFileAlreadyExists(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_dup_table']);
        $tester->execute(['name' => 'create_dup_table']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMakeMigrationAllowsDuplicateWithForce(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_force_table']);
        sleep(1); // ensure different timestamp
        $tester->execute(['name' => 'create_force_table', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testMakeMigrationCreatesDirectoryIfMissing(): void
    {
        // Remove the pre-created migrations directory
        $this->removeDir($this->tmpDir . '/database/migrations');
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/database/migrations');

        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_auto_dir_table']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->tmpDir . '/database/migrations');
    }

    public function testMakeMigrationOutputContainsSuccessMessage(): void
    {
        $tester = $this->tester(new GenerateMigration());
        $tester->execute(['name' => 'create_items_table']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('CreateItemsTable', $display);
    }

    // =========================================================================
    // §3  MigrateAction (migrate)  — SQLite :memory:
    // =========================================================================

    /**
     * Write a migration file whose up() creates a simple table via Schema.
     */
    private function writeSchemaCreateMigration(string $class, string $table): string
    {
        $dir = $this->tmpDir . '/database/migrations';
        $timestamp = date('Y_m_d_His');
        $file = "{$dir}/{$timestamp}_{$class}.php";

        file_put_contents($file, <<<PHP
<?php
use Foxdb\Migrations\Migration;
use Foxdb\Schema;
use Foxdb\Schema\Blueprint;

class {$class} extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$t) {
            \$t->id();
            \$t->string('name');
            \$t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
}
PHP);
        return $file;
    }

    public function testMigrateRunCreatesTable(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateMigrateRunTest', 'migrate_run_test');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertTrue(Schema::hasTable('migrate_run_test'));
    }

    public function testMigrateRunReportsNothingWhenAlreadyMigrated(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateAlreadyRan', 'already_ran');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);
        // Run again — should say nothing to migrate
        $tester->execute(['action' => 'run']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }

    public function testMigrateRollbackRevertsLastBatch(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateRollbackTest', 'rollback_test');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);
        $this->assertTrue(Schema::hasTable('rollback_test'));

        $tester->execute(['action' => 'rollback']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFalse(Schema::hasTable('rollback_test'));
    }

    public function testMigrateRollbackReportsNothingWhenNoMigrations(): void
    {
        $this->bootSqlite();

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'rollback']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to roll back', $tester->getDisplay());
    }

    public function testMigrateResetRollsBackAll(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateResetA', 'reset_a');
        sleep(1);
        $this->writeSchemaCreateMigration('CreateResetB', 'reset_b');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        $this->assertTrue(Schema::hasTable('reset_a'));
        $this->assertTrue(Schema::hasTable('reset_b'));

        $tester->execute(['action' => 'reset', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFalse(Schema::hasTable('reset_a'));
        $this->assertFalse(Schema::hasTable('reset_b'));
    }

    public function testMigrateRefreshReappliesAll(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateRefreshTest', 'refresh_test');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        $tester->execute(['action' => 'refresh', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertTrue(Schema::hasTable('refresh_test'));
    }

    public function testMigrateStatusShowsTable(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateStatusTest', 'status_test');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        $tester->execute(['action' => 'status']);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Migration Status', $display);
        $this->assertStringContainsString('CreateStatusTest', $display);
    }

    public function testMigrateFailsWithInvalidAction(): void
    {
        $this->bootSqlite();

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid action', $tester->getDisplay());
    }

    public function testMigrateFailsWhenMigrationsDirMissing(): void
    {
        $this->bootSqlite();
        $this->removeDir($this->tmpDir . '/database/migrations');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        $this->assertSame(1, $tester->getStatusCode());
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('does not exist', $display);
    }

    public function testMigrateRunWithStepOption(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateStepA', 'step_a');
        sleep(1);
        $this->writeSchemaCreateMigration('CreateStepB', 'step_b');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run', '--step' => '1']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        // Only the first migration should have run
        $this->assertTrue(Schema::hasTable('step_a'));
        $this->assertFalse(Schema::hasTable('step_b'));
    }

    public function testMigrateResetReportsCancelledWithoutForce(): void
    {
        $this->bootSqlite();
        $this->writeSchemaCreateMigration('CreateCancelTest', 'cancel_test');

        $tester = $this->tester(new MigrateAction());
        $tester->execute(['action' => 'run']);

        // Simulate user answering "no" to the confirmation
        $tester->setInputs(['no']);
        $tester->execute(['action' => 'reset']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('cancelled', $tester->getDisplay());
    }

    // =========================================================================
    // §4  DbAction (db)  — MySQL-specific
    // =========================================================================

    public function testDbInvalidActionReturnsFailure(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'unknown']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid action', $tester->getDisplay());
    }

    public function testDbListShowsDatabases(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'list']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Database List', $display);
        // mysql system database always exists
        $this->assertStringContainsString('mysql', $display);
    }

    public function testDbTablesShowsTablesForCurrentDatabase(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'tables']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Table List', $tester->getDisplay());
    }

    public function testDbTablesWithUseOption(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'tables', '--use' => 'mysql']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Table List', $display);
        $this->assertStringContainsString('mysql', $display);
    }

    public function testDbTablesRejectsInvalidDbName(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'tables', '--use' => '123invalid']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid database name', $tester->getDisplay());
    }

    public function testDbCreateAndDropDatabase(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $dbName = 'webrium_test_tmp_' . uniqid();

        // Create
        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'create', 'name' => $dbName]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('created successfully', $tester->getDisplay());

        // Drop (with --force to skip confirmation)
        $tester->execute(['action' => 'drop', 'name' => $dbName, '--force' => true]);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('deleted successfully', $tester->getDisplay());
    }

    public function testDbCreateFailsOnInvalidName(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'create', 'name' => '1bad-name']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid database name', $tester->getDisplay());
    }

    public function testDbCreateFailsWhenNameMissing(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'create']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('required', $tester->getDisplay());
    }

    public function testDbDropFailsWhenNameMissing(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'drop']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('required', $tester->getDisplay());
    }

    public function testDbDropCancelledWhenUserSaysNo(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $dbName = 'webrium_test_cancel_' . uniqid();
        // Create the DB first
        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'create', 'name' => $dbName]);

        // Simulate user typing "no"
        $tester->setInputs(['no']);
        $tester->execute(['action' => 'drop', 'name' => $dbName]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('cancelled', $tester->getDisplay());

        // Cleanup
        $tester->execute(['action' => 'drop', 'name' => $dbName, '--force' => true]);
    }

    public function testDbDropFailsOnInvalidName(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new DbAction());
        $tester->execute(['action' => 'drop', 'name' => '123bad']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid database name', $tester->getDisplay());
    }

    // =========================================================================
    // §5  TableAction (table)  — MySQL-specific
    // =========================================================================

    /**
     * Create a temporary test table in MySQL and return its name.
     * The table is auto-cleaned up by the DROP in tearDown (DB reset
     * destroys connections; the table persists in MySQL so we clean explicitly).
     */
    private function createTempTable(string $prefix = 'test_tbl'): string
    {
        $name = $prefix . '_' . uniqid();
        DB::statement("CREATE TABLE IF NOT EXISTS `$name` (
            `id`   INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `age`  INT DEFAULT 0
        ) ENGINE=InnoDB");
        return $name;
    }

    private function dropTempTable(string $name): void
    {
        try {
            DB::statement("DROP TABLE IF EXISTS `$name`");
        } catch (\Throwable) {
            // Ignore — table may already be gone
        }
    }

    public function testTableInvalidActionReturnsFailure(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'unknown', 'table_name' => 'foo']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid action', $tester->getDisplay());
    }

    public function testTableRejectsInvalidTableName(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'info', 'table_name' => '1bad-name']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid table name', $tester->getDisplay());
    }

    public function testTableInfoShowsColumns(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('info_tbl');
        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'info', 'table_name' => $table]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $display = $tester->getDisplay();
            $this->assertStringContainsString($table, $display);
            $this->assertStringContainsString('id', $display);
            $this->assertStringContainsString('name', $display);
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableColumnsShowsColumns(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('cols_tbl');
        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'columns', 'table_name' => $table]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableInfoFailsForNonExistentTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'info', 'table_name' => 'nonexistent_table_xyz']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testTableExistsReturnsTrueForExistingTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('exists_tbl');
        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'exists', 'table_name' => $table]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('exists', $tester->getDisplay());
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableExistsReturnsFalseForMissingTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'exists', 'table_name' => 'definitely_not_there_xyz']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testTableCountReportsRowCount(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('count_tbl');
        try {
            DB::statement("INSERT INTO `$table` (name) VALUES ('alice'), ('bob')");

            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'count', 'table_name' => $table]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('2', $tester->getDisplay());
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableDropWithForce(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('drop_tbl');

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'drop', 'table_name' => $table, '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('dropped', $tester->getDisplay());

        // Verify table is gone
        $exists = DB::select("SHOW TABLES LIKE '$table'");
        $this->assertEmpty($exists);
    }

    public function testTableDropCancelledWhenUserSaysNo(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('drop_cancel_tbl');
        try {
            $tester = $this->tester(new TableAction());
            $tester->setInputs(['no']);
            $tester->execute(['action' => 'drop', 'table_name' => $table]);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('Cancelled', $tester->getDisplay());

            // Table should still exist
            $exists = DB::select("SHOW TABLES LIKE '$table'");
            $this->assertNotEmpty($exists);
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableDropFailsForNonExistentTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'drop', 'table_name' => 'ghost_table_xyz', '--force' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testTableTruncateWithForce(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('truncate_tbl');
        try {
            DB::statement("INSERT INTO `$table` (name) VALUES ('x'), ('y'), ('z')");

            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'truncate', 'table_name' => $table, '--force' => true]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            $rows = DB::select("SELECT COUNT(*) as cnt FROM `$table`");
            $this->assertSame('0', (string) $rows[0]->cnt);
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableRenameTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $old = $this->createTempTable('rename_old');
        $new = 'rename_new_' . uniqid();

        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'rename', 'table_name' => $old, 'extra' => $new]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('renamed', $tester->getDisplay());

            $exists = DB::select("SHOW TABLES LIKE '$new'");
            $this->assertNotEmpty($exists);
        } finally {
            $this->dropTempTable($old);
            $this->dropTempTable($new);
        }
    }

    public function testTableRenameFailsWhenNewNameMissing(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('rename_noarg');
        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'rename', 'table_name' => $table]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString('required', $tester->getDisplay());
        } finally {
            $this->dropTempTable($table);
        }
    }

    public function testTableCopyTable(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $src  = $this->createTempTable('copy_src');
        $dest = 'copy_dst_' . uniqid();

        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'copy', 'table_name' => $src, 'extra' => $dest]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('copied', $tester->getDisplay());

            $exists = DB::select("SHOW TABLES LIKE '$dest'");
            $this->assertNotEmpty($exists);
        } finally {
            $this->dropTempTable($src);
            $this->dropTempTable($dest);
        }
    }

    public function testTableCopyFailsWhenDestExists(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $src  = $this->createTempTable('copy_src2');
        $dest = $this->createTempTable('copy_dst2');

        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'copy', 'table_name' => $src, 'extra' => $dest]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString('already exists', $tester->getDisplay());
        } finally {
            $this->dropTempTable($src);
            $this->dropTempTable($dest);
        }
    }

    public function testTableRunSqlFile(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $sqlTable = 'runsql_tbl_' . uniqid();
        $sqlFile  = $this->tmpDir . '/test.sql';
        file_put_contents($sqlFile, "CREATE TABLE IF NOT EXISTS `$sqlTable` (id INT PRIMARY KEY);");

        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'run', 'table_name' => $sqlFile]);

            $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
            $this->assertStringContainsString('executed', $tester->getDisplay());

            $exists = DB::select("SHOW TABLES LIKE '$sqlTable'");
            $this->assertNotEmpty($exists);
        } finally {
            $this->dropTempTable($sqlTable);
            @unlink($sqlFile);
        }
    }

    public function testTableRunSqlFileNotFound(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $tester = $this->tester(new TableAction());
        $tester->execute(['action' => 'run', 'table_name' => '/nonexistent/path.sql']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testTableRunSqlFileEmpty(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $sqlFile = $this->tmpDir . '/empty.sql';
        file_put_contents($sqlFile, '   ');

        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'run', 'table_name' => $sqlFile]);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString('empty', $tester->getDisplay());
        } finally {
            @unlink($sqlFile);
        }
    }

    public function testTableRejectsInvalidDbOptionName(): void
    {
        if (!$this->bootMysql()) {
            $this->markTestSkipped('MySQL not available.');
        }

        $table = $this->createTempTable('dbopt_tbl');
        try {
            $tester = $this->tester(new TableAction());
            $tester->execute(['action' => 'info', 'table_name' => $table, '--use' => '123bad']);

            $this->assertSame(1, $tester->getStatusCode());
            $this->assertStringContainsString('Invalid database name', $tester->getDisplay());
        } finally {
            $this->dropTempTable($table);
        }
    }
}
