<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 单个任务的执行结果（由 Runner 产出，供 Scheduler 汇总进 RunReport）。
 *
 * 用值对象承载“成功 / 跳过 / 失败”三种终态，使各种 Runner 实现
 * （同步、Fibers 协程、Parallel 多线程/进程）都能以统一形态返回结果，
 * 即便在隔离的线程/进程中执行，也能把结论带回主线程。
 */
final class TaskOutcome
{
    /** 终态常量。 */
    public const SUCCESS = 'success';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    /**
     * @param string         $name       任务名称
     * @param string         $status     终态：self::SUCCESS / SKIPPED / ERROR
     * @param mixed          $result     成功时的返回值
     * @param \Throwable|null $error      失败时的异常（跨进程返回时用 TaskError 承载）
     * @param string|null    $skipReason 跳过原因：'condition' / 'overlap' / null
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly mixed $result = null,
        public readonly ?\Throwable $error = null,
        public readonly ?string $skipReason = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function skipped(): bool
    {
        return $this->status === self::SKIPPED;
    }

    public function failed(): bool
    {
        return $this->status === self::ERROR;
    }
}
