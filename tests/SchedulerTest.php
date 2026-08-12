<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    public function test_run_executes_due_and_skips_not_due(): void
    {
        $ran = [];
        $sch = new Scheduler();
        $sch->call('a', function () use (&$ran) { $ran[] = 'a'; })->cron('* * * * *');
        $sch->call('b', function () use (&$ran) { $ran[] = 'b'; })->cron('0 0 1 1 *'); // 不命中
        $report = $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertSame(['a'], $ran);
        self::assertSame(1, $report->succeededCount());
        self::assertSame(0, $report->failedCount());
    }

    public function test_run_isolates_failures(): void
    {
        $ran = [];
        $sch = new Scheduler();
        $sch->call('ok', function () use (&$ran) { $ran[] = 'ok'; })->cron('* * * * *');
        $sch->call('boom', function () { throw new \RuntimeException('fail'); })->cron('* * * * *');
        $report = $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertContains('ok', $ran);
        self::assertSame(1, $report->failedCount());
        self::assertArrayHasKey('boom', $report->failures());
    }

    public function test_due_tasks_query(): void
    {
        $sch = new Scheduler();
        $sch->call('a', fn () => 1)->cron('* * * * *');
        $sch->call('b', fn () => 1)->cron('0 0 1 1 *');
        $due = $sch->dueTasks(new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertCount(1, $due);
        self::assertSame('a', $due[0]->name());
    }

    public function test_run_hooks_fire(): void
    {
        $before = false;
        $after = false;
        $sch = new Scheduler();
        $sch->beforeRun(function () use (&$before) { $before = true; });
        $sch->afterRun(function () use (&$after) { $after = true; });
        $sch->call('a', fn () => 1)->cron('* * * * *');
        $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertTrue($before);
        self::assertTrue($after);
    }

    public function test_environment_filter(): void
    {
        $sch = new Scheduler();
        $sch->environment('prod');
        $task = $sch->call('only-dev', fn () => 1)->cron('* * * * *')->environments('dev');
        self::assertFalse($task->shouldRun(new \DateTimeImmutable('2026-08-12 10:00:00'), 'prod'));
    }

    public function test_command_task_runs(): void
    {
        $sch = new Scheduler();
        $sch->command('echo hi', 'echo')->cron('* * * * *');
        $report = $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame(1, $report->succeededCount());
    }
}
