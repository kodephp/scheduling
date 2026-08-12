<?php

declare(strict_types=1);

namespace Kode\Scheduling\Exception;

/**
 * 调度器所有异常的基类。
 * 统一继承自此异常，便于调用方按类型捕获。
 */
class SchedulingError extends \Exception
{
}
