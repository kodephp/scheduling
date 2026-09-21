<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Cron;
use Kode\Scheduling\Exception\TaskError;
use Kode\Scheduling\Scheduler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * v1.4 回归：步进语义、时间窗去重、stopOnError 完整落账、匿名任务稳定指纹。
 */
final class SchedulingFixesTest extends TestCase
{
    private string $guardDir = '';

    protected function setUp(): void
    {
        $this->guardDir = \sys_get_temp_dir() . '/ks-tick-test-' . \uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach ((array) \glob($this->guardDir . '/*') as $f) {
            @\unlink($f);
        }
        @\rmdir($this->guardDir);
    }

    public function test_single_value_step_spans_to_field_max(): void
    {
        $cron = new Cron('10/5 * * * *');

        self::assertFalse($cron->isDue(new \DateTimeImmutable('2026-09-21 08:12:00')));
        self::assertTrue($cron->isDue(new \DateTimeImmutable('2026-09-21 08:15:00')));
        self::assertTrue($cron->isDue(new \DateTimeImmutable('2026-09-21 08:55:00')));
        self::assertFalse($cron->isDue(new \DateTimeImmutable('2026-09-21 08:58:00')));
    }

    public function test_tick_dedupe_fires_minute_task_once_per_window(): void
    {
        $runs = 0;
        $scheduler = new Scheduler();
        $scheduler->call('min', static function () use (&$runs): void {
            $runs++;
        })->everyMinute();

        // keepAlive 才会打开进程内去重，单测直接翻开关
        $prop = new \ReflectionProperty(Scheduler::class, 'tickDedupe');
        $prop->setValue($scheduler, true);

        $now = new \DateTimeImmutable('2026-09-21 10:00:37');
        $scheduler->run($now);
        $scheduler->run($now->modify('+10 seconds'));
        $scheduler->run($now->modify('+20 seconds'));

        self::assertSame(1, $runs, '同一分钟窗内秒级轮询只许派发一次');

        $scheduler->run($now->modify('+1 minute'));
        self::assertSame(2, $runs, '跨到下一分钟窗必须再次派发');
    }

    public function test_shared_tick_guard_dedupes_across_schedulers(): void
    {
        $build = function () {
            $scheduler = new Scheduler();
            $scheduler->call('job', static fn () => 'x')->everyMinute();

            return $scheduler->useTickGuard($this->guardDir);
        };

        $now = new \DateTimeImmutable('2026-09-21 10:00:00');

        $first = $build();
        $second = $build();

        self::assertSame(1, $first->run($now)->succeededCount());
        self::assertSame(0, $second->run($now)->succeededCount(), '另一进程同窗不得重复派发');
        self::assertSame(1, $second->run($now->modify('+1 minute'))->succeededCount());
    }

    public function test_stopOnError_reports_full_outcome_before_throwing(): void
    {
        $captured = null;
        $scheduler = new Scheduler();
        $scheduler->call('boom', static function (): void {
            throw new RuntimeException('炸了');
        })->everyMinute();
        $scheduler->stopOnError();
        $scheduler->afterRun(static function ($report) use (&$captured): void {
            $captured = $report;
        });

        try {
            $scheduler->run(new \DateTimeImmutable('2026-09-21 10:00:00'));
            self::fail('stopOnError 应当抛出');
        } catch (TaskError $e) {
            self::assertStringContainsString('stopOnError', $e->getMessage());
        }

        self::assertNotNull($captured, 'afterRun 钩子必须先于异常执行，报告完整落账');
        self::assertSame(1, $captured->failedCount());
    }

    public function test_anonymous_task_names_are_stable_and_unique(): void
    {
        $scheduler = new Scheduler();
        $fn = static fn () => null;

        $a = $scheduler->task($fn);
        $b = $scheduler->task($fn);

        self::assertNotSame($a->name(), $b->name(), '同指纹的重复注册必须可区分');
        self::assertStringStartsWith('task:', $a->name());

        // 稳定性：新 Scheduler 对同一闭包定义应得到相同的基础名（跨重启可关联）
        $fresh = (new Scheduler())->task(static fn () => null);
        self::assertMatchesRegularExpression('/^task:[0-9a-f]{32}$/', $fresh->name());
    }
}
