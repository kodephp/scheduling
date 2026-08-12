<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Contract\CoordinatorInterface;
use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Coordinator\LocalCoordinator;
use Kode\Scheduling\Mutex\FileMutex;
use Kode\Scheduling\Runner\SyncRunner;
use Kode\Scheduling\Scheduler;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;
use Kode\Scheduling\TaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * 验证框架抽象层与新增能力：TaskOutcome / Runner / Mutex / Coordinator 接入，
 * 以及标签筛选、成功/失败回调、启用开关、日志器注入。本库完全自包含，
 * 不依赖任何外部调度/并发包。
 */
final class FrameworkTest extends TestCase
{
    public function test_task_outcome_states(): void
    {
        self::assertTrue((new TaskOutcome('a', TaskStatus::Success, 1))->succeeded());
        self::assertTrue((new TaskOutcome('a', TaskStatus::Skipped, skipReason: 'overlap'))->skipped());
        self::assertTrue((new TaskOutcome('a', TaskStatus::Error, error: new \RuntimeException('x')))->failed());
    }

    public function test_sync_runner_runs_due_tasks(): void
    {
        $ran = [];
        $tasks = [
            new Task('a', static function () use (&$ran) {
                $ran[] = 'a';

                return 'A';
            }),
            new Task('boom', static function (): void {
                throw new \RuntimeException('fail');
            }),
        ];
        $outcomes = (new SyncRunner())->runAll($tasks, new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertCount(2, $outcomes);
        self::assertSame('A', $outcomes[0]->result);
        self::assertTrue($outcomes[0]->succeeded());
        self::assertTrue($outcomes[1]->failed());
        self::assertSame('fail', $outcomes[1]->error->getMessage());
    }

    public function test_sync_runner_reports_overlap_skip(): void
    {
        $lockFile = \sys_get_temp_dir() . '/ks-fw-' . \uniqid() . '.lock';
        @\unlink($lockFile);

        $scheduler = new Scheduler();
        $scheduler->call('ov', static function () {
            \usleep(50000);
        })->withoutOverlapping($lockFile);

        $r1 = $scheduler->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame(1, $r1->succeededCount());

        // 模拟锁仍被持有
        $fp = \fopen($lockFile, 'c');
        \flock($fp, \LOCK_EX);
        $r2 = $scheduler->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame(1, $r2->skippedCount());
        self::assertSame('overlap', $r2->skipped()['ov']);
        \fclose($fp);
        @\unlink($lockFile);
    }

    public function test_file_mutex_is_exclusive_then_releasable(): void
    {
        $mutex = new FileMutex();
        $key = 'kode:scheduling:overlap:fw-test';
        self::assertTrue($mutex->acquire($key, 30.0));
        // 同一逻辑键在已持有时（另一 FileMutex 实例）应拿不到
        self::assertFalse((new FileMutex())->acquire($key, 30.0));
        $mutex->release($key);
        // 释放后可再次获取
        self::assertTrue((new FileMutex())->acquire($key, 30.0));
        (new FileMutex())->release($key);
    }

    public function test_local_coordinator_always_dispatches(): void
    {
        $c = new LocalCoordinator();
        $c->tick();
        self::assertTrue($c->shouldDispatch());
    }

    public function test_scheduler_uses_injected_coordinator_to_block_dispatch(): void
    {
        $scheduler = new Scheduler();
        $scheduler->call('a', static fn () => 'ran')->cron('* * * * *');

        $blocked = new class () implements CoordinatorInterface {
            #[\Override]
            public function tick(): void
            {
            }

            #[\Override]
            public function shouldDispatch(): bool
            {
                return false;
            }
        };
        $scheduler->setCoordinator($blocked);

        $report = $scheduler->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertFalse($report->wasDispatched());
        self::assertSame(0, $report->succeededCount());
    }

    public function test_scheduler_uses_injected_runner(): void
    {
        $captured = [];
        $runner = new class ($captured) implements RunnerInterface {
            public function __construct(
                public array &$captured
            ) {
            }

            #[\Override]
            public function runAll(array $tasks, \DateTimeImmutable $now): array
            {
                foreach ($tasks as $t) {
                    $this->captured[] = $t->name();
                }

                return \array_map(
                    static fn (Task $t): TaskOutcome => new TaskOutcome($t->name(), TaskStatus::Success, 'ok'),
                    $tasks
                );
            }
        };

        $scheduler = new Scheduler();
        $scheduler->call('x', static fn () => 1)->cron('* * * * *');
        $scheduler->setRunner($runner);

        $report = $scheduler->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame(['x'], $captured);
        self::assertSame(1, $report->succeededCount());
    }

    public function test_run_by_tag_filters_tasks(): void
    {
        $ran = [];
        $sch = new Scheduler();
        $sch->call('backup', static function () use (&$ran) { $ran[] = 'backup'; })
            ->cron('* * * * *')->tag('db');
        $sch->call('report', static function () use (&$ran) { $ran[] = 'report'; })
            ->cron('* * * * *')->tag('daily');

        $report = $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'), 'db');
        self::assertSame(['backup'], $ran);
        self::assertSame(1, $report->succeededCount());
    }

    public function test_disabled_task_is_skipped(): void
    {
        $ran = [];
        $sch = new Scheduler();
        $sch->call('off', static function () use (&$ran) { $ran[] = 'off'; })
            ->cron('* * * * *')->enabled(false);

        $report = $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame([], $ran);
        self::assertSame(0, $report->succeededCount());
    }

    public function test_on_success_and_on_failure_callbacks(): void
    {
        $successHit = false;
        $failureHit = '';
        $sch = new Scheduler();
        $sch->call('ok', static fn () => 'res')
            ->cron('* * * * *')
            ->onSuccess(static function ($r) use (&$successHit) { $successHit = $r; });
        $sch->call('bad', static function (): void { throw new \RuntimeException('nope'); })
            ->cron('* * * * *')
            ->onFailure(static function ($e) use (&$failureHit) { $failureHit = $e->getMessage(); });

        $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame('res', $successHit);
        self::assertSame('nope', $failureHit);
    }

    public function test_logger_is_invoked_on_run(): void
    {
        $lines = [];
        $logger = new class ($lines) implements \Kode\Scheduling\LoggerInterface {
            public function __construct(public array &$lines)
            {
            }

            #[\Override]
            public function debug(string $message, array $context = []): void
            {
                $this->lines[] = "DEBUG $message";
            }

            #[\Override]
            public function info(string $message, array $context = []): void
            {
                $this->lines[] = "INFO $message";
            }

            #[\Override]
            public function warning(string $message, array $context = []): void
            {
                $this->lines[] = "WARN $message";
            }

            #[\Override]
            public function error(string $message, array $context = []): void
            {
                $this->lines[] = "ERROR $message";
            }
        };

        $sch = new Scheduler();
        $sch->call('a', static fn () => 1)->cron('* * * * *');
        $sch->setLogger($logger);

        $sch->run(new \DateTimeImmutable('2026-08-12 10:00:00'));

        self::assertContains('INFO 调度开始', $lines);
        self::assertContains('INFO 任务成功', $lines);
        self::assertContains('INFO 调度结束', $lines);
    }

    public function test_every_second_produces_six_field(): void
    {
        $task = (new Scheduler())->call('s', static fn () => 1)->everySecond();
        self::assertSame('*/1 * * * * *', $task->expression());
        self::assertTrue($task->hasSeconds());

        $task2 = (new Scheduler())->call('s2', static fn () => 1)->everySeconds(10);
        self::assertSame('*/10 * * * * *', $task2->expression());

        $task3 = (new Scheduler())->call('s3', static fn () => 1)->dailyAt('03:30')->second(15);
        self::assertSame('15 30 3 * * *', $task3->expression());
        self::assertTrue($task3->hasSeconds());
    }
}
