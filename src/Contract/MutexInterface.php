<?php

declare(strict_types=1);

namespace Kode\Scheduling\Contract;

/**
 * 互斥锁契约（用于防重入 / 防重叠执行）。
 *
 * withoutOverlapping() 依赖它保证“同一任务同一时刻只有一个执行单元在跑”。
 * 通过替换实现，可在单节点与分布式集群间无缝切换：
 *
 *  - FileMutex    ：默认，基于本地文件锁（flock），仅对“同一台机器”有效；
 *  - ProcessMutex  ：基于 kode/process 的分布式锁，跨节点互斥（需共享存储后端）。
 *
 * 约定：acquire() 成功返回 true 并持有锁直到显式 release()；同进程内可重入由实现自行决定。
 */
interface MutexInterface
{
    /**
     * 尝试获取锁。
     *
     * @param string $key         锁名（逻辑键；FileMutex 会映射为临时文件，ProcessMutex 用作分布式锁名）
     * @param float  $ttlSeconds  锁存活时长（秒）；应大于临界区预期耗时，避免死锁
     * @return bool               获取成功返回 true，已被他人持有返回 false
     */
    public function acquire(string $key, float $ttlSeconds): bool;

    /**
     * 释放锁（由 acquire() 成功的一方调用）。
     */
    public function release(string $key): void;
}
