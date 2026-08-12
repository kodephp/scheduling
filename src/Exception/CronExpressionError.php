<?php

declare(strict_types=1);

namespace Kode\Scheduling\Exception;

/**
 * Cron 表达式非法时抛出。
 */
class CronExpressionError extends SchedulingError
{
    /**
     * 构造一个表达式错误。
     *
     * @param string         $expression 原始表达式
     * @param string         $reason     错误原因
     * @param int            $code       错误码
     * @param \Throwable|null $previous   上游异常
     */
    public static function for(string $expression, string $reason, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('非法的 Cron 表达式 "%s"：%s', $expression, $reason), $code, $previous);
    }
}
