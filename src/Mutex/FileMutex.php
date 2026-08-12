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
        // 若调用方传入的是“显式文件路径”（含路径分隔符或以 .lock 结尾），则原样使用，
        // 以兼容 withoutOverlapping('/var/run/xxx.lock') 这类历史用法；
        // 其余情况（逻辑锁名）映射为临时目录下的安全文件名。
        if (\str_contains($key, \DIRECTORY_SEPARATOR)
            || \str_ends_with($key, '.lock')
            || \str_ends_with($key, '.pid')) {
            return $key;
        }

        $dir = $this->dir !== '' ? $this->dir : \sys_get_temp_dir();
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '-', $key);

        return $dir . \DIRECTORY_SEPARATOR . 'ks-mutex-' . \substr(\md5($safe), 0, 16) . '.lock';
    }
}
