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

    public function test_six_field_seconds_is_due(): void
    {
        // 6 段：秒 分 时 日 月 周
        $c = new Cron('30 0 12 * * *'); // 每天 12:00:30
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-12 12:00:30')));
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-12 12:00:31')));
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-12 12:00:00')));
        self::assertTrue($c->hasSeconds());
    }

    public function test_six_field_every_seconds(): void
    {
        $c = new Cron('*/15 * * * * *'); // 每 15 秒
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-12 10:00:15')));
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-12 10:00:45')));
        self::assertFalse($c->isDue(new \DateTimeImmutable('2026-08-12 10:00:10')));
    }

    public function test_six_field_next_run_steps_by_second(): void
    {
        // 每秒触发：nextRun 必须严格晚于 $from 且步长为秒
        $now = new \DateTimeImmutable('2026-08-12 10:00:00');
        $next = (new Cron('*/1 * * * * *'))->nextRun($now);
        self::assertSame('2026-08-12 10:00:01', $next->format('Y-m-d H:i:s'));
    }

    public function test_five_field_has_no_seconds(): void
    {
        $c = new Cron('30 3 * * *');
        self::assertFalse($c->hasSeconds());
        // 5 段表达式不校验秒，任意秒都命中
        self::assertTrue($c->isDue(new \DateTimeImmutable('2026-08-12 03:30:47')));
    }

    public function test_invalid_six_field_throws(): void
    {
        $this->expectException(CronExpressionError::class);
        new Cron('* * * * * * *'); // 7 段非法
    }

    public function test_describe_includes_seconds(): void
    {
        self::assertStringContainsString('每 30 秒', (new Cron('*/30 * * * * *'))->describe());
        self::assertStringContainsString('第 0 秒', (new Cron('0 * * * * *'))->describe());
    }
}
