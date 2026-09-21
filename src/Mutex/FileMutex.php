<?php

declare(strict_types=1);

namespace Kode\Scheduling\Mutex;

use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Lock;

/**
 * 默认互斥锁：基于本地文件锁（flock）。
 *
 * 仅对“同一台机器”有效——同一主机上的多个进程/多个 Scheduler 实例
 * 不会同时跑同一任务；但不同机器之间无感知。跨节点互斥请自行实现
 * MutexInterface（如基于 Redis）后通过 setMutex() 替换。
 *
 * 逻辑锁名会被安全地映射为临时目录下的一个锁文件，因此调用方可以放心使用
 * 任意字符串作为 key（如 "kode:scheduling:overlap:order-sync"）。
 */
final class FileMutex implements MutexInterface
{
    /** @var array<string, Lock> 已持有的锁，按 key 缓存以便精确释放 */
    private array $held = [];

    public function __construct(
        private readonly string $dir = ''
    ) {
    }

    /**
     * 尝试获取锁。
     *
     * 关于 `$ttlSeconds`：flock 随进程退出由操作系统自动释放，
     * 「持锁进程死了」在这里不存在，所以本地文件锁无需（也不能）按 TTL 抢占——
     * 强行到期抢占等于把 withoutOverlapping 的语义打破（长任务会被重叠执行）。
     * 该参数仅在分布式互斥实现（如 Redis SETNX）中有意义，此处忽略。
     */
    #[\Override]
    public function acquire(string $key, float $ttlSeconds): bool
    {
        $path = $this->pathFor($key);
        $lock = new Lock($path);
        if ($lock->acquire()) {
            $this->held[$key] = $lock;

            return true;
        }

        return false;
    }

    #[\Override]
    public function release(string $key): void
    {
        if (isset($this->held[$key])) {
            $this->held[$key]->release();
            unset($this->held[$key]);
        }
    }

    /** 把逻辑锁名映射为合法的文件路径。 */
    private function pathFor(string $key): string
    {
        // 「显式路径」直通仅对【绝对路径且不含 .. 段】开放，兼容
        // withoutOverlapping('/var/run/xxx.lock') 这类历史用法；
        // 锁名可能来自数据库/外部配置，相对路径与 ../ 穿越一律拒绝，
        // 落到哈希分支——绝不在调用方控制的任意位置创建/打开文件。
        if (\str_starts_with($key, \DIRECTORY_SEPARATOR)
            && !\in_array('..', \explode(\DIRECTORY_SEPARATOR, $key), true)) {
            return $key;
        }

        $dir = $this->dir !== '' ? $this->dir : \sys_get_temp_dir();
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '-', $key);

        return $dir . \DIRECTORY_SEPARATOR . 'ks-mutex-' . \substr(\md5($safe), 0, 16) . '.lock';
    }
}
