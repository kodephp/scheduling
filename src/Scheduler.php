<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Exception\TaskError;

/**
 * 调度器：任务的注册中心与执行引擎。
 *
 * 典型用法：
 * <code>
 *   $scheduler = new Scheduler();
 *   $scheduler->call('备份', fn () => backup())->dailyAt('02:00');
 *   $scheduler->command('php artisan queue:work')->everyMinute();
 *
 *   // 通常由系统 crontab 每分钟触发一次本进程
 *   $report = $scheduler->run();
 * </code>
 *
 * 健壮性设计：
 *  - run() 默认“单任务失败不中断其余任务”，逐任务隔离异常；
 *  - 可通过 onError() 统一捕获错误，或 stopOnError() 改为遇错即停；
 *  - 未到期的任务直接跳过，不占用执行资源。
 */
final class Scheduler
{
    /** @var list<Task> */
    private array $tasks = [];

    private \DateTimeZone $timezone;

    /** 当前运行环境，与 Task::environments() 比对。 */
    private string $environment = '';

    /** 全局错误处理器 (Task $task, \Throwable $e)。 */
    private ?\Closure $errorHandler = null;

    /** 遇错是否中断整个 run()。 */
    private bool $stopOnError = false;

    /**
     * @param \DateTimeZone|null $timezone 调度器基准时区（用于解析“现在”）
     */
    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new \DateTimeZone(\date_default_timezone_get());
    }

    /** 设定调度器基准时区。 */
    public function timezone(\DateTimeZone|string $timezone): static
    {
        $this->timezone = \is_string($timezone) ? new \DateTimeZone($timezone) : $timezone;

        return $this;
    }

    /** 设定当前运行环境（用于环境过滤）。 */
    public function environment(string $env): static
    {
        $this->environment = $env;

        return $this;
    }

    /** 注册全局错误处理器；任一任务抛异常时回调（仍会被记录进报告）。 */
    public function onError(\Closure $handler): static
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /** 设定为“任一任务失败即中断整个 run()”（默认关闭，逐任务隔离）。 */
    public function stopOnError(bool $stop = true): static
    {
        $this->stopOnError = $stop;

        return $this;
    }

    // ------------------------------------------------------------------
    // 任务注册
    // ------------------------------------------------------------------

    /**
     * 注册一个具名任务（callback 可为闭包、函数名、对象方法、可调用对象等）。
     *
     * @return Task 返回任务实例以便继续流畅配置
     */
    public function call(string $name, callable $callback): Task
    {
        return $this->register(new Task($name, $callback));
    }

    /**
     * 注册一个匿名任务，名称自动生成（基于回调指纹）。
     *
     * @return Task
     */
    public function task(callable $callback, ?string $name = null): Task
    {
        $name ??= 'task:' . $this->callbackFingerprint($callback);

        return $this->register(new Task($name, $callback));
    }

    /**
     * 注册一个 shell 命令任务（通过 shell_exec 执行，返回其输出）。
     *
     * @return Task
     */
    public function command(string $command, ?string $name = null): Task
    {
        $name ??= 'cmd:' . \md5($command);

        return $this->call($name, static function () use ($command): ?string {
            return \shell_exec($command);
        });
    }

    // ------------------------------------------------------------------
    // 查询与执行
    // ------------------------------------------------------------------

    /** 所有已注册任务。 */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** 按名称查找任务；不存在返回 null。 */
    public function find(string $name): ?Task
    {
        foreach ($this->tasks as $task) {
            if ($task->name() === $name) {
                return $task;
            }
        }

        return null;
    }

    /** 返回在 $now 到期且应执行的任务（尚未真正运行）。 */
    public function dueTasks(\DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->tasks as $task) {
            if ($task->shouldRun($now, $this->environment)) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * 执行所有到期任务。
     *
     * @param \DateTimeImmutable|null $now 基准时刻；默认取当前时刻（调度器时区）
     */
    public function run(?\DateTimeImmutable $now = null): RunReport
    {
        $now = $now ?? new \DateTimeImmutable('now', $this->timezone);
        $report = new RunReport($now);

        foreach ($this->tasks as $task) {
            if (!$task->isDue($now)) {
                continue; // 未到期，直接跳过
            }
            if (!$task->shouldRun($now, $this->environment)) {
                $report->addSkipped($task->name(), 'condition');

                continue;
            }

            try {
                $result = $task->run();
                if ($task->lastSkipReason() === 'overlap') {
                    $report->addSkipped($task->name(), 'overlap');
                } else {
                    $report->addSuccess($task->name(), $result);
                }
            } catch (\Throwable $e) {
                $report->addFailure($task->name(), $e);
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($task, $e);
                }
                if ($this->stopOnError) {
                    throw TaskError::for($task->name(), '执行失败且已开启 stopOnError', 0, $e);
                }
            }
        }

        return $report;
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    private function register(Task $task): Task
    {
        $this->tasks[] = $task;

        return $task;
    }

    /** 为匿名任务生成稳定指纹（用于默认名称）。 */
    private function callbackFingerprint(callable $callback): string
    {
        if (\is_string($callback)) {
            return \md5($callback);
        }
        if (\is_array($callback) && \count($callback) === 2) {
            [$obj, $method] = $callback;
            $class = \is_object($obj) ? \get_class($obj) : $obj;

            return \md5($class . '::' . $method);
        }
        if ($callback instanceof \Closure) {
            return \md5(\spl_object_hash($callback));
        }
        if (\is_object($callback)) {
            return \md5(\get_class($callback));
        }

        return \md5(\serialize($callback));
    }
}
