<?php

declare(strict_types=1);

namespace Kode\Scheduling\Exception;

/**
 * 调度器所有异常的基类。
 * 统一继承自此异常，便于调用方按类型捕获。
 */
class SchedulingError extends \Exception
{
    /**
     * 通用构造工厂。
     *
     * @param string         $component 出错的组件/位置（如 'FibersRunner'、'ProcessMutex'）
     * @param string         $reason    错误原因
     * @param int            $code      错误码
     * @param \Throwable|null $previous 上游异常
     */
    public static function for(string $component, string $reason, int $code = 0, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('[%s] %s', $component, $reason), $code, $previous);
    }
}
