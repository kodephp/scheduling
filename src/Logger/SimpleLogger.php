<?php

declare(strict_types=1);

namespace Kode\Scheduling\Logger;

use Kode\Scheduling\LoggerInterface;

/**
 * 简单日志器：按级别输出到 STDOUT / STDERR（error/warning 走 STDERR）。
 *
 * 输出格式：`[级别] 消息 {上下文JSON}`。适合直接接入常驻守护进程或调试。
 * 需要更丰富的格式（带时间戳、文件、多通道）时，自行实现 LoggerInterface 即可。
 */
final class SimpleLogger implements LoggerInterface
{
    public function __construct(
        private readonly bool $echo = true,
    ) {
    }

    #[\Override]
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context, \STDOUT);
    }

    #[\Override]
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context, \STDOUT);
    }

    #[\Override]
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context, \STDERR);
    }

    #[\Override]
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context, \STDERR);
    }

    /** 统一写出；非 TTY 环境（如管道）也安全。 */
    private function write(string $level, string $message, array $context, $stream): void
    {
        if (!$this->echo) {
            return;
        }
        $line = \sprintf(
            '[%s] %s%s',
            $level,
            $message,
            $context === [] ? '' : ' ' . \json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
        ) . \PHP_EOL;
        \fwrite($stream, $line);
    }
}
