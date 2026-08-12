<?php

declare(strict_types=1);

namespace Kode\Scheduling\Exception;

/**
 * 任务配置或执行相关的错误。
 */
class TaskError extends SchedulingError
{
    /**
     * 构造一个任务错误。
     *
     * @param string         $taskName 任务名称
     * @param string         $reason   错误原因
     * @param int            $code     错误码
     * @param \Throwable|null $previous 上游异常
     */
    public static function for(string $taskName, string $reason, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('任务 "%s" 出错：%s', $taskName, $reason), $code, $previous);
    }
}
