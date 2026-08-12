<?php

declare(strict_types=1);

namespace Kode\Scheduling\Tests;

use Kode\Scheduling\Cron;
use Kode\Scheduling\Exception\CronExpressionError;
use PHPUnit\Framework\TestCase;

final class CronTest extends TestCase
{
    public function test_is_due_daily_at(): void
    {
        $c = new Cron('30 3 * * *');
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-12 03:30:00')));
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-12 03:31:00')));
    }

    public function test_weekday_restriction(): void
    {
        $c = new Cron('0 9 * * 1-5');
        // 2026-08-10 是周一
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-10 09:00:00')));
        // 2026-08-09 是周日
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-09 09:00:00')));
    }

    public function test_day_or_week_rule(): void
    {
        $c = new Cron('0 0 1 * 1');
        // 每月 1 号（2026-09-01 周二）
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-09-01 00:00:00')));
        // 周一（2026-08-10）
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-10 00:00:00')));
        // 周三（2026-08-12）
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-12 00:00:00')));
    }

    public function test_step_and_names_and_macros(): void
    {
        self::assertTrue((new Cron('*/15 * * * *'))->isDue(new \DateTimeImmutable('2026-08-12 10:15:00')));
        self::assertFalse((new Cron('*/15 * * * *'))->isDue(new \DateTimeImmutable('2026-08-12 10:17:00')));
        // 名称
        self::assertTrue((new Cron('0 0 1 JAN MON'))->isDue(new \DateTimeImmutable('2026-01-05 00:00:00')));
        // 宏
        self::assertTrue((new Cron('@hourly'))->isDue(new \DateTimeImmutable('2026-08-12 10:00:00')));
    }

    public function test_invalid_expression_throws(): void
    {
        $this->expectException(CronExpressionError::class);
        new Cron('99 * * * *');
    }

    public function test_next_run_finds_sunday(): void
    {
        $next = (new Cron('0 0 * * 0'))->nextRun(new \DateTimeImmutable('2026-08-12 10:00:00'));
        self::assertSame('0', $next->format('w'));
        self::assertSame('2026-08-16', $next->format('Y-m-d'));
    }

    public function test_next_run_is_strictly_after_now(): void
    {
        // 当前整点已命中每小时整点，应返回下一个整点而非当前时刻
        $now = new \DateTimeImmutable('2026-08-12 10:00:00');
        $next = (new Cron('0 * * * *'))->nextRun($now);
        self::assertSame('2026-08-12 11:00:00', $next->format('Y-m-d H:i:s'));

        // 每分钟：当前已命中，应返回下一分钟
        $next2 = (new Cron('* * * * *'))->nextRun($now);
        self::assertSame('2026-08-12 10:01:00', $next2->format('Y-m-d H:i:s'));
    }

    public function test_describe_common_patterns(): void
    {
        self::assertSame('每分钟', (new Cron('* * * * *'))->describe());
        self::assertSame('每 5 分钟', (new Cron('*/5 * * * *'))->describe());
        self::assertSame('03:30', (new Cron('30 3 * * *'))->describe());
        self::assertStringContainsString('工作日', (new Cron('0 9 * * 1-5'))->describe());
        self::assertSame('每月 1 号，00:00', (new Cron('0 0 1 * *'))->describe());
    }
}
