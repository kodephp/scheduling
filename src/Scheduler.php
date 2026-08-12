<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Contract\CoordinatorInterface;
use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Coordinator\LocalCoordinator;
use Kode\Scheduling\Exception\TaskError;
use Kode\Scheduling\Mutex\FileMutex;
use Kode\Scheduling\Runner\SyncRunner;

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

    /** 整轮运行开始前的钩子列表（参数为基准时刻 DateTimeImmutable）。 */
    private array $beforeRuns = [];

    /** 整轮运行结束后的钩子列表（参数为本次 RunReport）。 */
    private array $afterRuns = [];

    /** keepAlive 守护循环是否继续运行。 */
    private bool $keepRunning = false;

    /** 执行器：决定“如何跑”到期任务（同步/协程/并行）。 */
    private ?RunnerInterface $runner = null;

    /** 互斥锁：决定“如何在防重叠时互斥”（本地文件/分布式）。 */
    private ?MutexInterface $mutex = null;

    /** 协调器：决定“本节点是否应当派发”（单机/集群 Leader）。 */
    private ?CoordinatorInterface $coordinator = null;

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

    /** 注册整轮运行开始前的钩子（回调接收基准时刻 DateTimeImmutable）。 */
    public function beforeRun(callable $callback): static
    {
        $this->beforeRuns[] = $callback;

        return $this;
    }

    /** 注册整轮运行结束后的钩子（回调接收本次 RunReport）。 */
    public function afterRun(callable $callback): static
    {
        $this->afterRuns[] = $callback;

        return $this;
    }

    // ------------------------------------------------------------------
    // 执行模型（可替换的执行器 / 互斥锁 / 协调器）
    // ------------------------------------------------------------------

    /** 设置执行器（线程/协程/并行模型）。默认 SyncRunner（顺序同步）。 */
    public function setRunner(RunnerInterface $runner): static
    {
        $this->runner = $runner;

        return $this;
    }

    /** 设置互斥锁实现（防重叠）。默认 FileMutex（本机文件锁）。 */
    public function setMutex(MutexInterface $mutex): static
    {
        $this->mutex = $mutex;

        return $this;
    }

    /** 设置协调器（单节点 / 集群 Leader）。默认 LocalCoordinator（恒派发）。 */
    public function setCoordinator(CoordinatorInterface $coordinator): static
    {
        $this->coordinator = $coordinator;

        return $this;
    }

    /** 当前执行器（惰性默认 SyncRunner）。 */
    public function runner(): RunnerInterface
    {
        return $this->runner ??= new SyncRunner();
    }

    /** 当前互斥锁（惰性默认 FileMutex，且会注入到所有任务）。 */
    public function mutex(): MutexInterface
    {
        return $this->mutex ??= new FileMutex();
    }

    /** 当前协调器（惰性默认 LocalCoordinator）。 */
    public function coordinator(): CoordinatorInterface
    {
        return $this->coordinator ??= new LocalCoordinator();
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

        foreach ($this->beforeRuns as $cb) {
            $cb($now);
        }

        // 1) 协调器裁决：非派发节点（如集群非 Leader）本次直接空转
        $this->coordinator()->tick();
        if (!$this->coordinator()->shouldDispatch()) {
            $report->setDispatched(false);
            foreach ($this->afterRuns as $cb) {
                $cb($report);
            }

            return $report;
        }

        // 2) 收集到期且应执行的任务（环境/条件/时间窗口不满足的记为跳过）
        $runnable = [];
        foreach ($this->tasks as $task) {
            if (!$task->isDue($now)) {
                continue; // 未到期，直接跳过（不计入报告）
            }
            if (!$task->shouldRun($now, $this->environment)) {
                $report->addSkipped($task->name(), 'condition');

                continue;
            }
            $runnable[] = $task;
        }

        // 3) 交给执行器批量执行（同步/协程/并行），单任务失败不影响其余
        $outcomes = $this->runner()->runAll($runnable, $now);
        foreach ($outcomes as $outcome) {
            if ($outcome->succeeded()) {
                $report->addSuccess($outcome->name, $outcome->result);
            } elseif ($outcome->skipped()) {
                $report->addSkipped($outcome->name, $outcome->skipReason ?? 'condition');
            } else {
                $report->addFailure($outcome->name, $outcome->error ?? TaskError::for($outcome->name, '未知执行错误'));
                if ($this->errorHandler !== null) {
                    $failed = $this->find($outcome->name);
                    ($this->errorHandler)($failed ?? $outcome->name, $outcome->error);
                }
                if ($this->stopOnError) {
                    throw TaskError::for($outcome->name, '执行失败且已开启 stopOnError', 0, $outcome->error);
                }
            }
        }

        foreach ($this->afterRuns as $cb) {
            $cb($report);
        }

        return $report;
    }

    /**
     * 守护模式：以 $intervalSeconds 为间隔循环执行，直到收到退出信号。
     *
     * 适合以常驻进程方式运行（配合 nohup/supervisor）。收到 SIGINT/SIGTERM
     * 时会优雅停止当前等待并退出（pcntl 扩展可用时生效）。
     *
     * @param int $intervalSeconds 轮询间隔（秒），默认 60
     */
    public function keepAlive(int $intervalSeconds = 60): void
    {
        if ($intervalSeconds < 1) {
            throw TaskError::for('__scheduler__', 'keepAlive 间隔必须 >= 1 秒');
        }

        if (\function_exists('pcntl_signal')) {
            $stop = function (): void {
                $this->keepRunning = false;
            };
            \pcntl_signal(\SIGINT, $stop);
            \pcntl_signal(\SIGTERM, $stop);
        }

        $this->keepRunning = true;
        while ($this->keepRunning) {
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
            $this->run();
            // 分片睡眠，便于及时响应信号
            $elapsed = 0;
            while ($this->keepRunning && $elapsed < $intervalSeconds) {
                \sleep(1);
                $elapsed++;
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    private function register(Task $task): Task
    {
        // 注入当前互斥锁实现，使 withoutOverlapping() 在分布式下也能生效
        $task->setMutex($this->mutex());

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
