<?php

declare(strict_types=1);

namespace Kode\Scheduling\Runner;

use Kode\Parallel\Pool\WorkerPool;
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Exception\SchedulingError;
use Kode\Scheduling\Exception\TaskError;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;

/**
 * 并行执行器：基于 kode/parallel 的多线程/多进程池执行到期任务。
 *
 * 适用场景：任务为 CPU 密集型（加解密、图像处理、批量计算），用真线程
 *          （ext-parallel / ZTS）或独立进程并发，绕开 PHP 单线程瓶颈。
 *
 * 工作机制：把每个任务的“回调”提交到 WorkerPool，由独立线程/进程执行，
 *           主线程 wait() 汇总结果。并发数/引擎/引导文件均可配置。
 *
 * 重要约束（由 kode/parallel 的执行模型决定）：
 *  1. 任务回调必须“可跨边界传递”——即对于进程引擎需可序列化，对于 parallel
 *     引擎其捕获变量也需可序列化；推荐使用“顶层命名函数 / 可调用类 / 不捕获
 *     外部状态的闭包”。闭包若捕获了数据库连接、Socket 等资源将无法传递。
 *  2. 由于回调在隔离的执行单元中运行，本模式的回调不会收到 Task 实例作为首参
 *     （Task 对象本身不可跨边界），也不会走 Task 内的文件锁逻辑——跨节点互斥
 *     请交由 ProcessMutex + LeaderCoordinator 负责。
 *  3. 异常在 worker 内被捕获并序列化为“类别+消息”带回主线程，用 TaskError 承载，
 *     原始堆栈在 worker 侧日志中可见。
 *
 * 依赖：composer require kode/parallel（未安装时实例化会给出明确提示）。
 */
final class ParallelRunner implements RunnerInterface
{
    /**
     * @param int         $concurrency 并发度；0=由 kode/parallel 按 CPU 自动决定
     * @param string|null $engine      引擎：'parallel'（真线程，需 ZTS）或 'process'（多进程）；null=自动
     * @param string|null $bootstrap   引导文件路径（进程引擎下用于预加载类/函数/autoload）
     */
    public function __construct(
        private readonly int $concurrency = 0,
        private readonly ?string $engine = null,
        private readonly ?string $bootstrap = null,
    ) {
        if (!\class_exists(WorkerPool::class)) {
            throw SchedulingError::for('ParallelRunner', '未安装 kode/parallel，请先执行：composer require kode/parallel');
        }
    }

    public function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        if ($tasks === []) {
            return [];
        }

        $pool = new WorkerPool($this->concurrency, $this->engine, $this->bootstrap);

        // 在 worker 内包裹一层“安全壳”，把任何异常转成可序列化的数组，
        // 避免 wait() 因某个任务抛错而整体失败、且能逐任务区分成败。
        $safe = static function (callable $cb): array {
            try {
                return ['ok' => true, 'value' => $cb()];
            } catch (\Throwable $e) {
                return ['ok' => false, 'class' => $e::class, 'msg' => $e->getMessage()];
            }
        };

        $futures = [];
        foreach ($tasks as $task) {
            // 仅提交“回调”本身；回调在隔离单元中执行，不传入 Task 实例（见类注释约束）。
            $futures[] = $pool->submit($safe, [$task->callback()]);
        }

        try {
            /** @var list<array{ok:bool,value?:mixed,class?:string,msg?:string}> $results */
            $results = $pool->wait();
        } catch (\Throwable $e) {
            // wait() 整体失败（极端情况）：所有任务统一记为错误
            $pool->close();

            return \array_map(
                static fn (Task $t): TaskOutcome => new TaskOutcome(
                    $t->name(),
                    TaskOutcome::ERROR,
                    error: TaskError::for($t->name(), '并行执行汇总失败：' . $e->getMessage(), 0, $e)
                ),
                $tasks
            );
        }

        $outcomes = [];
        foreach ($tasks as $i => $task) {
            $res = $results[$i] ?? ['ok' => false, 'class' => '', 'msg' => '无返回'];
            if ($res['ok']) {
                $outcomes[] = new TaskOutcome($task->name(), TaskOutcome::SUCCESS, $res['value'] ?? null);
            } else {
                $outcomes[] = new TaskOutcome(
                    $task->name(),
                    TaskOutcome::ERROR,
                    error: TaskError::for($task->name(), $res['msg'] ?? '并行任务执行失败', 0, null, $res['class'] ?: null)
                );
            }
        }

        $pool->close();

        return $outcomes;
    }
}
