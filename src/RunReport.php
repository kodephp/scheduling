<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 一次调度运行的汇总报告。
 *
 * 记录运行时刻、成功执行的任务及其返回值、失败任务及其异常、
 * 以及因条件/锁冲突被跳过的任务，便于事后审计与日志输出。
 */
final class RunReport
{
    /** @var array<string, mixed> 任务名 => 返回值 */
    private array $succeeded = [];

    /** @var array<string, \Throwable> 任务名 => 异常 */
    private array $failed = [];

    /** @var array<string, string> 任务名 => 跳过原因（condition/overlap） */
    private array $skipped = [];

    /** 本节点本次是否真正派发了任务（被协调器否决时为 false，如非集群 Leader）。 */
    private bool $dispatched = true;

    /**
     * @param \DateTimeImmutable $ranAt 本次运行的基准时刻
     */
    public function __construct(
        private \DateTimeImmutable $ranAt
    ) {
    }

    /** 标记本节点本次是否真正派发（默认 true）。 */
    public function setDispatched(bool $dispatched): void
    {
        $this->dispatched = $dispatched;
    }

    /** 本节点本次是否真正派发了任务。 */
    public function wasDispatched(): bool
    {
        return $this->dispatched;
    }

    /** 本次运行的基准时刻。 */
    public function ranAt(): \DateTimeImmutable
    {
        return $this->ranAt;
    }

    /** 记录一次成功执行。 */
    public function addSuccess(string $name, mixed $result): void
    {
        $this->succeeded[$name] = $result;
    }

    /** 记录一次失败执行。 */
    public function addFailure(string $name, \Throwable $error): void
    {
        $this->failed[$name] = $error;
    }

    /** 记录一次跳过。 */
    public function addSkipped(string $name, string $reason): void
    {
        $this->skipped[$name] = $reason;
    }

    /** 成功任务列表：任务名 => 返回值。 */
    public function successes(): array
    {
        return $this->succeeded;
    }

    /** 失败任务列表：任务名 => 异常。 */
    public function failures(): array
    {
        return $this->failed;
    }

    /** 被跳过任务列表：任务名 => 原因。 */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** 成功执行数量。 */
    public function succeededCount(): int
    {
        return \count($this->succeeded);
    }

    /** 失败执行数量。 */
    public function failedCount(): int
    {
        return \count($this->failed);
    }

    /** 跳过数量。 */
    public function skippedCount(): int
    {
        return \count($this->skipped);
    }

    /** 实际尝试运行（成功+失败）的任务数。 */
    public function totalRun(): int
    {
        return $this->succeededCount() + $this->failedCount();
    }

    /** 是否全部成功（无失败）。注意：跳过不计入失败。 */
    public function allSucceeded(): bool
    {
        return $this->failedCount() === 0;
    }
}
