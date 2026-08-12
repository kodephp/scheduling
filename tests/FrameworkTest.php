<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Contract\CoordinatorInterface;
use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Coordinator\LocalCoordinator;
use Kode\Scheduling\Exception\SchedulingError;
use Kode\Scheduling\Mutex\FileMutex;
use Kode\Scheduling\Runner\FibersRunner;
use Kode\Scheduling\Runner\ParallelRunner;
use Kode\Scheduling\Runner\SyncRunner;
use Kode\Scheduling\Scheduler;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;
use PHPUnit\Framework\TestCase;

/**
 * 验证分布式框架抽象层：TaskOutcome / Runner / Mutex / Coordinator，
 * 以及 Scheduler 对可替换组件的接入。外部包（kode/*）缺失时适配器应给出明确报错。
 */
final class FrameworkTest extends TestCase
{
    public function test_task_outcome_states(): void
    {
        self::assertTrue((new TaskOutcome('a', TaskOutcome::SUCCESS, 1))->succeeded());
        self::assertTrue((new TaskOutcome('a', TaskOutcome::SKIPPED, skipReason: 'overlap'))->skipped());
        self::assertTrue((new TaskOutcome('a', TaskOutcome::ERROR, error: new \RuntimeException('x')))->failed());
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
            public function tick(): void
            {
            }

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

            public function runAll(array $tasks, \DateTimeImmutable $now): array
            {
                foreach ($tasks as $t) {
                    $this->captured[] = $t->name();
                }

                return \array_map(
                    static fn (Task $t): TaskOutcome => new TaskOutcome($t->name(), TaskOutcome::SUCCESS, 'ok'),
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

    public function test_fibers_runner_throws_without_package(): void
    {
        if (\class_exists(\Kode\Fibers\Core\FiberPool::class)) {
            self::markTestSkipped('kode/fibers 已安装');

            return;
        }
        $this->expectException(SchedulingError::class);
        new FibersRunner();
    }

    public function test_parallel_runner_throws_without_package(): void
    {
        if (\class_exists(\Kode\Parallel\Pool\WorkerPool::class)) {
            self::markTestSkipped('kode/parallel 已安装');

            return;
        }
        $this->expectException(SchedulingError::class);
        new ParallelRunner();
    }

    public function test_process_mutex_throws_without_package(): void
    {
        if (\class_exists(\Kode\Process\Cluster::class)) {
            self::markTestSkipped('kode/process 已安装');

            return;
        }
        $this->expectException(SchedulingError::class);
        new \Kode\Scheduling\Mutex\ProcessMutex();
    }

    public function test_leader_coordinator_throws_without_package(): void
    {
        if (\class_exists(\Kode\Process\Cluster::class)) {
            self::markTestSkipped('kode/process 已安装');

            return;
        }
        $this->expectException(SchedulingError::class);
        new \Kode\Scheduling\Coordinator\LeaderCoordinator();
    }
}
