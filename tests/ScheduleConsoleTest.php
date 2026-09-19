<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webrium\App;
use Webrium\Directory;
use Webrium\Schedule;

use Webrium\Console\GenerateSchedule;
use Webrium\Console\ScheduleRun;

/**
 * Unit tests for the task-scheduler console commands.
 *
 * §1  GenerateSchedule (make:schedule)
 * §2  ScheduleRun (schedule:run)
 */
class ScheduleConsoleTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webrium_schedule_console_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        App::initialize($this->tmpDir);
        Directory::initDefaultStructure();
        Schedule::reset();

        foreach (['app/Schedules', 'storage/framework/schedule-locks', 'storage/logs'] as $dir) {
            mkdir($this->tmpDir . '/' . $dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        Schedule::reset();
        $this->removeDir($this->tmpDir);
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

    private function tester(object $command): CommandTester
    {
        return new CommandTester($command);
    }

    // =========================================================================
    // §1  GenerateSchedule
    // =========================================================================

    public function testMakeScheduleCreatesFile(): void
    {
        $tester = $this->tester(new GenerateSchedule());
        $tester->execute(['name' => 'SendReminders']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Schedules/SendReminders.php');
    }

    public function testMakeScheduleFailsOnInvalidName(): void
    {
        $tester = $this->tester(new GenerateSchedule());
        $tester->execute(['name' => 'send-reminders']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Invalid schedule name', $tester->getDisplay());
    }

    public function testMakeScheduleFailsIfExistsWithoutForce(): void
    {
        $tester = $this->tester(new GenerateSchedule());
        $tester->execute(['name' => 'Reminders']);
        $tester->execute(['name' => 'Reminders']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testMakeScheduleOverwritesWithForce(): void
    {
        $tester = $this->tester(new GenerateSchedule());
        $tester->execute(['name' => 'Reminders']);
        $tester->execute(['name' => 'Reminders', '--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testMakeScheduleCreatesDirectoryIfMissing(): void
    {
        // Existing (upgraded) projects won't have app/Schedules yet.
        rmdir($this->tmpDir . '/app/Schedules');

        $tester = $this->tester(new GenerateSchedule());
        $tester->execute(['name' => 'FirstTask']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($this->tmpDir . '/app/Schedules/FirstTask.php');
    }

    // =========================================================================
    // §2  ScheduleRun
    // =========================================================================

    public function testScheduleRunReportsNoTasksDueWhenDirectoryIsEmpty(): void
    {
        $tester = $this->tester(new ScheduleRun());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No tasks due', $tester->getDisplay());
    }

    public function testScheduleRunExecutesADueTaskFromDisk(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Always.php',
            '<?php \\Webrium\\Schedule::call(function () {'
                . 'file_put_contents($GLOBALS["__marker"], "ran");'
                . '})->name("always")->everyMinute();'
        );
        $marker = $this->tmpDir . '/marker.txt';
        $GLOBALS['__marker'] = $marker;

        $tester = $this->tester(new ScheduleRun());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('always', $tester->getDisplay());
        $this->assertFileExists($marker);

        unset($GLOBALS['__marker']);
    }

    public function testScheduleRunReportsFailedTaskButExitsNonZero(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Bad.php',
            '<?php \\Webrium\\Schedule::call(function () { throw new \\RuntimeException("nope"); })'
                . '->name("bad-task")->everyMinute();'
        );

        $tester = $this->tester(new ScheduleRun());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('bad-task', $tester->getDisplay());
        $this->assertStringContainsString('failed', $tester->getDisplay());
    }

    public function testScheduleRunContinuesAfterAFailingTask(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Bad.php',
            '<?php \\Webrium\\Schedule::call(function () { throw new \\RuntimeException("nope"); })'
                . '->name("bad-task")->everyMinute();'
        );
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Good.php',
            '<?php \\Webrium\\Schedule::call(fn () => null)->name("good-task")->everyMinute();'
        );

        $tester = $this->tester(new ScheduleRun());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('bad-task', $display);
        $this->assertStringContainsString('good-task', $display);
    }

    public function testScheduleRunReportsBrokenFileLoadErrorAndExitsNonZero(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Broken.php',
            '<?php throw new \\RuntimeException("cannot load");'
        );

        $tester = $this->tester(new ScheduleRun());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Failed to load', $tester->getDisplay());
        $this->assertStringContainsString('cannot load', $tester->getDisplay());
    }
}
