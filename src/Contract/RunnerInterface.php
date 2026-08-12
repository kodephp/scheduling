<?php

declare(strict_types=1);

namespace Kode\Scheduling\Contract;

use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;

/**
 * 任务执行器契约。
 *
 * 调度器把“已判定为到期且应执行”的任务交给 Runner 真正跑起来。
 * 通过替换 Runner，框架可在不改动业务代码的前提下切换执行模型：
 *
 *  - SyncRunner    ：默认，单进程顺序执行（最稳，调试友好）；
 *  - FibersRunner  ：基于 kode/fibers 的协程池并发执行（I/O 密集型友好）；
 *  - ParallelRunner：基于 kode/parallel 的多线程/多进程池执行（CPU 密集型友好）。
 *
 * runAll() 必须返回与入参顺序一致的 TaskOutcome 列表，且“单任务失败不得中断其余任务”。
 */
interface RunnerInterface
{
    /**
     * 批量执行多个到期任务。
     *
     * @param list<Task>       $tasks 已判定为应执行的任务
     * @param \DateTimeImmutable $now  基准时刻（与任务时区无关，由 Task 内部解释）
     * @return list<TaskOutcome>        与 $tasks 顺序一致的结果
     */
    public function runAll(array $tasks, \DateTimeImmutable $now): array;
}
