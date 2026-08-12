<?php

declare(strict_types=1);

namespace Kode\Scheduling\Runner;

use Kode\Fibers\Core\FiberPool;
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Exception\SchedulingError;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;

/**
 * 协程执行器：基于 kode/fibers 的协程池并发执行到期任务。
 *
 * 适用场景：任务多为 I/O 密集型（HTTP、数据库、消息队列），且会在协程内
 *          自然让出（await/sleep），从而获得高并发、低开销。
 *
 * 工作机制：把每个 Task 的“完整生命周期”（条件/锁/重试/回调）包成一个
 *           callable，交给 FiberPool::concurrent() 以协程方式并发跑；
 *           协程共享同一进程内存，因此 Task 实例与 $now 可直接捕获，无序列化成本。
 *
 * 注意：协程是“协作式并发”，若任务回调是纯 CPU 密集且不主动让出，并不会
 *       真正并行化——那种场景请改用 ParallelRunner（真线程/进程）。
 *
 * 依赖：composer require kode/fibers（未安装时实例化会给出明确提示）。
 */
final class FibersRunner implements RunnerInterface
{
    /**
     * @param array<string, mixed> $config FiberPool 构造参数（并发数、超时等），透传给 kode/fibers
     */
    public function __construct(
        private readonly array $config = []
    ) {
        if (!\class_exists(FiberPool::class)) {
            throw SchedulingError::for('FibersRunner', '未安装 kode/fibers，请先执行：composer require kode/fibers');
        }
    }

    public function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        if ($tasks === []) {
            return [];
        }

        $pool = new FiberPool($this->config);

        // 每个任务包成“返回 TaskOutcome”的闭包，内部自行捕获异常，
        // 这样 concurrent() 拿到的永远是可控结果，不会因单个任务抛错而中断整批。
        $wrappers = [];
        foreach ($tasks as $task) {
            $wrappers[] = function () use ($task, $now): TaskOutcome {
                try {
                    $result = $task->run($now);
                    if ($task->lastSkipReason() !== null) {
                        return new TaskOutcome($task->name(), TaskOutcome::SKIPPED, skipReason: $task->lastSkipReason());
                    }

                    return new TaskOutcome($task->name(), TaskOutcome::SUCCESS, $result);
                } catch (\Throwable $e) {
                    return new TaskOutcome($task->name(), TaskOutcome::ERROR, error: $e);
                }
            };
        }

        /** @var list<TaskOutcome> $results */
        $results = $pool->concurrent($wrappers, null);

        return $results;
    }
}
