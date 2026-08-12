<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 极简日志契约。
 *
 * 框架自带 NullLogger / SimpleLogger，不依赖 PSR-3；若项目已使用 PSR-3
 * 日志器，可令其实现本接口（或直接包一层）后 setLogger() 注入即可。
 * 上下文 $context 为任意键值对，具体渲染方式由实现决定。
 */
interface LoggerInterface
{
    public function debug(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;
}
