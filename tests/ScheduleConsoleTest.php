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
use Webrium\Console\ScheduleList;
use Webrium\Console\ScheduleTestCommand;
use Webrium\Console\ScheduleWork;

/**
 * Unit tests for the task-scheduler console commands.
 *
 * §1  GenerateSchedule (make:schedule)
 * §2  ScheduleRun (schedule:run)
 * §3  ScheduleList (schedule:list)
 * §4  ScheduleTestCommand (schedule:test)
 * §5  ScheduleWork (schedule:work)
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

    // =========================================================================
    // §3  ScheduleList
    // =========================================================================

    public function testScheduleListReportsNoTasksWhenDirectoryIsEmpty(): void
    {
        $tester = $this->tester(new ScheduleList());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No scheduled tasks registered', $tester->getDisplay());
    }

    public function testScheduleListShowsNameAndExpressionForEachTask(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Reports.php',
            '<?php \\Webrium\\Schedule::call(fn () => null)->name("reports.daily")->dailyAt("01:00");'
        );

        $tester = $this->tester(new ScheduleList());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('reports.daily', $display);
        $this->assertStringContainsString('0 1 * * *', $display);
    }

    public function testScheduleListReportsBrokenFileLoadErrorAndExitsNonZero(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Broken.php',
            '<?php throw new \\RuntimeException("cannot load for listing");'
        );

        $tester = $this->tester(new ScheduleList());
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Failed to load', $tester->getDisplay());
    }

    // =========================================================================
    // §4  ScheduleTestCommand
    // =========================================================================

    public function testScheduleTestReportsNoTasksWhenDirectoryIsEmpty(): void
    {
        $tester = $this->tester(new ScheduleTestCommand());
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No scheduled tasks registered', $tester->getDisplay());
    }

    public function testScheduleTestRunsNamedTaskImmediatelyIgnoringItsSchedule(): void
    {
        // Scheduled for 03:00 daily — schedule:test must run it right now anyway.
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Reports.php',
            '<?php \\Webrium\\Schedule::call(function () {'
                . 'file_put_contents($GLOBALS["__marker"], "ran");'
                . '})->name("reports.daily")->dailyAt("03:00");'
        );
        $marker = $this->tmpDir . '/marker.txt';
        $GLOBALS['__marker'] = $marker;

        $tester = $this->tester(new ScheduleTestCommand());
        $tester->execute(['name' => 'reports.daily']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('reports.daily', $tester->getDisplay());
        $this->assertFileExists($marker);

        unset($GLOBALS['__marker']);
    }

    public function testScheduleTestFailsForUnknownTaskName(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Reports.php',
            '<?php \\Webrium\\Schedule::call(fn () => null)->name("reports.daily")->daily();'
        );

        $tester = $this->tester(new ScheduleTestCommand());
        $tester->execute(['name' => 'does.not.exist']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No scheduled task named', $tester->getDisplay());
    }

    public function testScheduleTestExitsNonZeroWhenTaskThrows(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Bad.php',
            '<?php \\Webrium\\Schedule::call(function () { throw new \\RuntimeException("boom"); })'
                . '->name("bad-task")->daily();'
        );

        $tester = $this->tester(new ScheduleTestCommand());
        $tester->execute(['name' => 'bad-task']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testScheduleTestPromptsWhenNameIsOmitted(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Reports.php',
            '<?php \\Webrium\\Schedule::call(function () {'
                . 'file_put_contents($GLOBALS["__marker"], "ran");'
                . '})->name("reports.daily")->daily();'
        );
        $marker = $this->tmpDir . '/marker.txt';
        $GLOBALS['__marker'] = $marker;

        $tester = $this->tester(new ScheduleTestCommand());
        $tester->setInputs(['reports.daily']);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileExists($marker);

        unset($GLOBALS['__marker']);
    }

    // =========================================================================
    // §5  ScheduleWork
    // =========================================================================

    public function testWorkPrintsStartedAndStoppedMessages(): void
    {
        $command = new ScheduleWorkTestDouble();
        $command->limitTicksForTesting(1);

        $tester = $this->tester($command);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Schedule worker started', $display);
        $this->assertStringContainsString('Schedule worker stopped', $display);
    }

    /**
     * Regression guard: tick() must reset the registry before reloading, or
     * a long-running worker re-registers every task file on every tick
     * (loadFromDirectory() always re-requires each file) — the same task
     * would then run once per tick, PLUS N accumulated duplicate copies of
     * itself by the Nth tick, instead of exactly once per tick.
     */
    public function testWorkDoesNotAccumulateDuplicateRunsAcrossTicks(): void
    {
        $counter = $this->tmpDir . '/run-count.log';
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Counter.php',
            '<?php \\Webrium\\Schedule::call(function () {'
                . 'file_put_contents($GLOBALS["__counter"], "x", FILE_APPEND);'
                . '})->name("counter")->everyMinute();'
        );
        $GLOBALS['__counter'] = $counter;

        $command = new ScheduleWorkTestDouble();
        $command->limitTicksForTesting(3);

        $tester = $this->tester($command);
        $tester->execute([]);

        $this->assertSame('xxx', file_get_contents($counter), 'exactly one run per tick, not an accumulating duplicate per tick');

        unset($GLOBALS['__counter']);
    }

    public function testWorkReportsLoadErrorsOnEachTick(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/Broken.php',
            '<?php throw new \\RuntimeException("cannot load in worker");'
        );

        $command = new ScheduleWorkTestDouble();
        $command->limitTicksForTesting(1);

        $tester = $this->tester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Failed to load', $tester->getDisplay());
        $this->assertStringContainsString('cannot load in worker', $tester->getDisplay());
    }

    public function testWorkDoesNotRunTasksThatAreNotDue(): void
    {
        file_put_contents(
            $this->tmpDir . '/app/Schedules/NotDue.php',
            '<?php \\Webrium\\Schedule::call(fn () => null)->name("not-due-task")->dailyAt("03:00");'
        );

        $command = new ScheduleWorkTestDouble();
        $command->limitTicksForTesting(1);

        $tester = $this->tester($command);
        $tester->execute([]);

        $this->assertStringNotContainsString('not-due-task', $tester->getDisplay());
    }
}

/**
 * Test double for ScheduleWork: skips the real (up to 59-second) sleep so
 * the loop can be exercised in a fast, deterministic unit test.
 */
class ScheduleWorkTestDouble extends ScheduleWork
{
    protected function sleepUntilNextMinute(): void
    {
        // No real sleep in tests.
    }
}
