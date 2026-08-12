<?php

declare(strict_types=1);

namespace Kode\Scheduling\Runner;

use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;
use Kode\Scheduling\TaskStatus;

/**
 * 默认执行器：单进程、顺序、同步执行。
 *
 * 不引入任何额外依赖，最稳定、最易调试，适合绝大多数业务场景。
 * 每个任务内部仍走 Task 自身的“条件/锁/重试”逻辑，失败不会中断其余任务。
 *
 * 如需更高并发，可自行实现 RunnerInterface（例如基于 PHP 原生 Fiber 或
 * 并行扩展），并通过 Scheduler::setRunner() 替换，业务代码无需改动。
 */
final class SyncRunner implements RunnerInterface
{
    #[\Override]
    public function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        $outcomes = [];
        foreach ($tasks as $task) {
            $outcomes[] = $this->runOne($task, $now);
        }

        return $outcomes;
    }

    private function runOne(Task $task, \DateTimeImmutable $now): TaskOutcome
    {
        try {
            $result = $task->run($now);
            if ($task->lastSkipReason() !== null) {
                // 被锁/条件在 Task 内部拦截，归类为跳过
                return new TaskOutcome($task->name(), TaskStatus::Skipped, skipReason: $task->lastSkipReason());
            }

            return new TaskOutcome($task->name(), TaskStatus::Success, $result);
        } catch (\Throwable $e) {
            return new TaskOutcome($task->name(), TaskStatus::Error, error: $e);
        }
    }
}
