<?php

declare(strict_types=1);

namespace Kode\Scheduling\Logger;

use Kode\Scheduling\LoggerInterface;

/**
 * 空日志器：吞掉所有日志，不产生任何输出。
 *
 * 作为 setLogger() 的默认实现，保证“未配置日志时功能完全不受影响”。
 */
final class NullLogger implements LoggerInterface
{
    #[\Override]
    public function debug(string $message, array $context = []): void
    {
    }

    #[\Override]
    public function info(string $message, array $context = []): void
    {
    }

    #[\Override]
    public function warning(string $message, array $context = []): void
    {
    }

    #[\Override]
    public function error(string $message, array $context = []): void
    {
    }
}
