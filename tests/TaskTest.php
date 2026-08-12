<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Scheduler;
use PHPUnit\Framework\TestCase;

final class TaskTest extends TestCase
{
    public function test_description_getter(): void
    {
        $task = (new Scheduler())->call('d', fn () => 1)
            ->dailyAt('03:30')
            ->description('每日备份');
        self::assertSame('每日备份', $task->getDescription());
        self::assertSame('30 3 * * *', $task->expression());
    }

    public function test_between_window(): void
    {
        $task = (new Scheduler())->call('w', fn () => 'x')
            ->cron('* * * * *')
            ->between('10:00', '10:05');
        self::assertTrue($task->shouldRun(new \DateTimeImmutable('2026-08-12 10:03:00')));
        self::assertFalse($task->shouldRun(new \DateTimeImmutable('2026-08-12 10:30:00')));
    }

    public function test_unless_between_window(): void
    {
        $task = (new Scheduler())->call('n', fn () => 'x')
            ->cron('* * * * *')
            ->unlessBetween('10:00', '10:05');
        self::assertFalse($task->shouldRun(new \DateTimeImmutable('2026-08-12 10:03:00')));
        self::assertTrue($task->shouldRun(new \DateTimeImmutable('2026-08-12 10:30:00')));
    }

    public function test_retry_eventually_succeeds(): void
    {
        $attempts = 0;
        $task = (new Scheduler())->call('r', function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new \RuntimeException('boom');
            }
            return 'ok';
        })->cron('* * * * *')->retry(2);

        self::assertSame('ok', $task->run(new \DateTimeImmutable('2026-08-12 10:00:00')));
        self::assertSame(2, $attempts);
    }

    public function test_retry_exhausted_throws(): void
    {
        $task = (new Scheduler())->call('r', function () {
            throw new \RuntimeException('always');
        })->cron('* * * * *')->retry(1, 0);

        $this->expectException(\RuntimeException::class);
        $task->run(new \DateTimeImmutable('2026-08-12 10:00:00'));
    }

    public function test_run_skips_on_failed_condition(): void
    {
        $task = (new Scheduler())->call('c', fn () => 1)
            ->cron('* * * * *')
            ->when(fn () => false);
        self::assertNull($task->run(new \DateTimeImmutable('2026-08-12 10:00:00')));
        self::assertSame('condition', $task->lastSkipReason());
    }

    public function test_timezone_aware_due(): void
    {
        $task = (new Scheduler())->call('tz', fn () => 1)
            ->cron('0 0 * * *')
            ->timezone('Asia/Shanghai');
        // UTC 16:00 == 上海 00:00
        self::assertTrue($task->isDue(new \DateTimeImmutable('2026-08-12 16:00:00', new \DateTimeZone('UTC'))));
        self::assertFalse($task->isDue(new \DateTimeImmutable('2026-08-12 15:00:00', new \DateTimeZone('UTC'))));
    }
}
