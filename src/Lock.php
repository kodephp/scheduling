<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 基于文件锁（flock）的互斥锁。
 *
 * 用于保证同一任务不会重叠执行：先到者持有锁，
 * 后到者获取失败即视为“已有实例在运行”，应跳过本次执行。
 *
 * 注意：进程退出时操作系统会自动释放该进程持有的 flock，
 * 因此即使未显式 release() 也不会造成死锁，但显式释放仍是好习惯。
 */
final class Lock
{
    private $handle = null;

    /**
     * @param string $path 锁文件路径，建议落在系统临时目录且按任务名唯一
     */
    public function __construct(
        private string $path
    ) {
    }

    /**
     * 尝试非阻塞获取锁。
     *
     * @return bool 获取成功返回 true；已被其它进程持有则返回 false
     */
    public function acquire(): bool
    {
        $dir = \dirname($this->path);
        if (!\is_dir($dir)) {
            // 递归创建目录，忽略权限导致的失败（交由后续 fopen 报错）
            @\mkdir($dir, 0o777, true);
        }

        $handle = @\fopen($this->path, 'c');
        if ($handle === false) {
            return false;
        }

        // LOCK_EX 排它锁 + LOCK_NB 非阻塞
        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    /** 释放锁并关闭文件句柄。幂等调用安全。 */
    public function release(): void
    {
        if ($this->handle !== null) {
            \flock($this->handle, \LOCK_UN);
            \fclose($this->handle);
            $this->handle = null;
        }
    }

    /** 锁文件路径（可用于调试）。 */
    public function path(): string
    {
        return $this->path;
    }
}
