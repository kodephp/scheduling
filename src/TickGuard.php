<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 跨进程「时间窗派发戳」。
 *
 * 解决多节点/多进程同时跑同一个 Scheduler 时的重复派发问题：
 * 每个 (任务, 时间窗) 组合在共享目录里落一个标记文件，
 * 在 flock 保护下「读旧窗、写新窗」原子完成——抢到者派发，来晚者静默跳过。
 *
 * 与 withoutOverlapping 的分工：
 *  - TickGuard 管「这一分钟该不该由我来触发」（派发去重，锁随拿随放）；
 *  - 互斥锁管「上一轮还没跑完就别重入」（执行互斥，锁贯穿整个执行期）。
 *
 * 崩溃语义：标记在派发前写入，执行中崩溃不会在同一时间窗内重试
 * （at-most-once per window），避免崩溃循环导致的重复副作用。
 */
final class TickGuard
{
    public function __construct(
        private readonly string $dir
    ) {
    }

    /**
     * 尝试为 $key 认领 $window（如 '2026-09-21 10:00'）。
     *
     * @return bool true = 本次认领成功（应当派发）；false = 已被认领或存储不可用
     */
    public function claim(string $key, string $window): bool
    {
        if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0o775, true) && !\is_dir($this->dir)) {
            return false;
        }

        $path = $this->dir . \DIRECTORY_SEPARATOR . 'ks-tick-' . \substr(\md5($key), 0, 24) . '.stamp';
        // 'c+'：读写兼备（'c' 是只写，回读旧窗会触发 EBADF）
        $handle = @\fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            // 另一进程正在裁决同一时间窗——按「已被认领」处理，宁跳过不重跑
            \fclose($handle);

            return false;
        }

        try {
            $claimed = (string) \stream_get_contents($handle);
            if ($claimed === $window) {
                return false;
            }

            \ftruncate($handle, 0);
            \rewind($handle);
            \fwrite($handle, $window);
            \fflush($handle);

            return true;
        } finally {
            \flock($handle, \LOCK_UN);
            \fclose($handle);
        }
    }

    /** 存储目录（可用于调试）。 */
    public function dir(): string
    {
        return $this->dir;
    }
}
