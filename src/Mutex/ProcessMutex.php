<?php

declare(strict_types=1);

namespace Kode\Scheduling\Mutex;

use Kode\Process\Cluster;
use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Exception\SchedulingError;

/**
 * 分布式互斥锁：基于 kode/process 的 DistributedLock。
 *
 * 借助 kode/process 的集群存储后端（file / redis / globaldata，自动择优），
 * 让“同一把锁”在多个节点间互斥——从而多机部署时，任意时刻只有一个节点
 * 能拿到某任务的执行权，天然避免重复执行。
 *
 * 配合 LeaderCoordinator 使用时：Leader 负责派发，ProcessMutex 负责兜底防重入，
 * 两者叠加即可在 Leader 切换的瞬间也不出现“双跑”。
 *
 * 依赖：composer require kode/process（未安装时实例化会给出明确提示）。
 * 可选：Cluster::make('redis', [...]) 显式指定存储后端后再使用本锁。
 */
final class ProcessMutex implements MutexInterface
{
    /** @var array<string, \Kode\Process\Cluster\Lock\DistributedLock> 已持有的锁对象，按 key 缓存以便精确释放 */
    private array $held = [];

    /**
     * @param float $defaultTtl 默认锁存活时长（秒）；应大于任务预期耗时，必要时由调用方按需传参
     */
    public function __construct(
        private readonly float $defaultTtl = 30.0
    ) {
        if (!\class_exists(Cluster::class)) {
            throw SchedulingError::for('ProcessMutex', '未安装 kode/process，请先执行：composer require kode/process');
        }
    }

    public function acquire(string $key, float $ttlSeconds): bool
    {
        $lock = Cluster::lock($key, $ttlSeconds);
        if ($lock->tryAcquire()) {
            $this->held[$key] = $lock;

            return true;
        }

        return false;
    }

    public function release(string $key): void
    {
        if (isset($this->held[$key])) {
            $this->held[$key]->release();
            unset($this->held[$key]);
        }
    }
}
