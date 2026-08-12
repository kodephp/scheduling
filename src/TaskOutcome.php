<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 单个任务的执行结果（由 Runner 产出，供 Scheduler 汇总进 RunReport）。
 *
 * 用值对象承载“成功 / 跳过 / 失败”三种终态，使各种 Runner 实现
 * （当前仅内置 SyncRunner，亦可自行实现 RunnerInterface 扩展并发模型）
 * 都能以统一形态返回结果，便于 Scheduler 一致地汇总。
 */
final class TaskOutcome
{
    /**
     * @param TaskStatus       $status     终态
     * @param mixed            $result     成功时的返回值
     * @param \Throwable|null  $error      失败时的异常（跨进程返回时用 TaskError 承载）
     * @param string|null      $skipReason 跳过原因：'condition' / 'overlap' / null
     */
    public function __construct(
        public readonly string $name,
        public readonly TaskStatus $status,
        public readonly mixed $result = null,
        public readonly ?\Throwable $error = null,
        public readonly ?string $skipReason = null,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === TaskStatus::Success;
    }

    public function skipped(): bool
    {
        return $this->status === TaskStatus::Skipped;
    }

    public function failed(): bool
    {
        return $this->status === TaskStatus::Error;
    }
}
