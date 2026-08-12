# kode/scheduling

通用 Cron 调度框架（PHP 8.3+）。支持任意 `callable` / `task` / `command`，命名简短无冲突，
健壮可扩展，并可无缝接入 **kode/fibers（协程并发）**、**kode/parallel（并行执行）**、
**kode/process（分布式锁 + Leader 选举）** 三件套，从单机平滑演进到分布式集群。

> 仓库：`https://github.com/kodephp/scheduling` ｜ 许可：MIT ｜ 最低版本：PHP 8.3

---

## 目录

- [特性](#特性)
- [安装](#安装)
- [30 秒上手](#30-秒上手)
- [架构总览](#架构总览)
- [三种运行模式](#三种运行模式)
  - [模式一：单机同步（默认）](#模式一单机同步默认)
  - [模式二：同节点并发（fibers / parallel）](#模式二同节点并发fibers--parallel)
  - [模式三：多节点分布式（process）](#模式三多节点分布式process)
- [任务配置速查](#任务配置速查)
- [调度器 API](#调度器-api)
- [CLI 命令行](#cli-命令行)
- [由 crontab 驱动](#由-crontab-驱动)
- [设计原则与健壮性](#设计原则与健壮性)
- [常见问题](#常见问题)

---

## 特性

- **任意任务**：闭包、函数名、对象方法、可调用对象、`shell` 命令皆可。
- **标准 Cron**：5 段表达式 + `@hourly/@daily` 等宏，支持范围/列表/步进、月周英文名、
  Vixie cron 的「日/周 OR」语义；`describe()` 输出中文可读描述。
- **流畅配置**：`dailyAt()`、`everyFiveMinutes()`、`weekdays()`、`between()`、`retry()`、
  `withoutOverlapping()`、`when()`、`environments()` 等链式调用。
- **可替换的执行模型**：同步 / 协程 / 并行，业务代码零改动切换。
- **可替换的互斥锁**：本地文件锁 / 分布式锁，防重入、防双跑。
- **可替换的协调器**：单机恒派发 / 集群仅 Leader 派发，高可用不重复。
- **逐任务隔离**：单任务失败不中断其余任务；全局 `onError()` / `stopOnError()`。
- **结构化报告**：每次运行产出 `RunReport`（成功/失败/跳过明细）。
- **守护模式**：`keepAlive()` 常驻循环，信号优雅退出。

---

## 安装

```bash
composer require kode/scheduling
```

按需安装「增强组件」（**均为可选**，不装也能用默认单机模式）：

```bash
composer require kode/fibers     # 协程并发执行（I/O 密集型）
composer require kode/parallel   # 多线程/多进程并行执行（CPU 密集型，建议 ZTS）
composer require kode/process    # 分布式锁 + Leader 选举（集群部署）
```

---

## 30 秒上手

```php
<?php
require 'vendor/autoload.php';

use Kode\Scheduling\Scheduler;

$scheduler = new Scheduler();
$scheduler->timezone('Asia/Shanghai')->environment('prod');

// 任意 callable
$scheduler->call('备份数据库', fn () => backup_db())
    ->dailyAt('02:00')
    ->withoutOverlapping();

// 工作日 09:00
$scheduler->call('日报', fn () => send_report())
    ->weekdays()->at('09:00');

// 原生 cron 表达式
$scheduler->task(fn () => sync(), '同步')
    ->cron('*/15 9-18 * * 1-5');

// 由系统 crontab 每分钟触发本进程
$report = $scheduler->run();
// $report->succeededCount() / failedCount() / skipped()
```

---

## 架构总览

调度器把「**派发决策**」与「**执行方式**」「**互斥方式**」三者解耦，各自由一个可替换的
契约驱动。业务只关心「何时跑、跑什么」，运行模型随时可换：

```
                        ┌─────────────────────────────┐
   crontab 每分钟 ──────▶│         Scheduler           │
   (或 keepAlive 常驻)   │   run(DateTimeImmutable)    │
                        └──────────────┬──────────────┘
                                       │ 1. 协调器裁决：本节点派发吗？
                  CoordinatorInterface │    LocalCoordinator（默认，恒派发）
                                       │    LeaderCoordinator（kode/process，仅 Leader）
                                       ▼
                               筛出到期 + 应执行的任务
                                       │ 2. 执行器批量执行
                    RunnerInterface    │    SyncRunner（默认，顺序同步）
                                       │    FibersRunner（kode/fibers 协程池）
                                       │    ParallelRunner（kode/parallel 线程/进程池）
                                       ▼
                每个 Task 内部：条件 → 互斥锁 → 重试 → 回调
                  MutexInterface       FileMutex（默认，本地 flock）
                                       ProcessMutex（kode/process 分布式锁）
```

三个契约接口位于 `Kode\Scheduling\Contract\`：

| 契约 | 职责 | 默认实现 | 分布式实现 |
|------|------|----------|------------|
| `RunnerInterface` | 如何执行到期任务 | `SyncRunner` | `FibersRunner` / `ParallelRunner` |
| `MutexInterface` | 防重叠互斥 | `FileMutex` | `ProcessMutex` |
| `CoordinatorInterface` | 是否派发 | `LocalCoordinator` | `LeaderCoordinator` |

每个实现都通过 `Scheduler::setRunner() / setMutex() / setCoordinator()` 注入，
调度器与具体执行/协调机制完全解耦。

---

## 三种运行模式

### 模式一：单机同步（默认）

零依赖，最稳。所有到期任务在**同一进程内顺序**执行，失败互不影响。

```php
$scheduler = new Scheduler();
$scheduler->call('a', fn () => a())->everyMinute();
$scheduler->call('b', fn () => b())->hourly();
$scheduler->run();
```

适合绝大多数业务。调试、日志、异常追踪最直接。

### 模式二：同节点并发（fibers / parallel）

当任务数量多或单个任务耗时长，可让**同一台机器上的多个任务并发**执行。

#### 协程并发（I/O 密集型）— kode/fibers

任务多为 HTTP / DB / 消息队列等 I/O 等待时，用协程池并发，开销极低：

```bash
composer require kode/fibers
```

```php
use Kode\Scheduling\Runner\FibersRunner;

$scheduler->setRunner(new FibersRunner([
    'size' => 64,   // 协程池大小（透传给 kode/fibers）
]));
$scheduler->run();
```

> 协程是协作式并发：任务回调会在 I/O 等待时让出，从而高并发；
> 纯 CPU 密集且不主动让出的回调不会真正并行，那种场景请用 `ParallelRunner`。

#### 并行执行（CPU 密集型）— kode/parallel

任务为加解密、图像处理、批量计算等 CPU 密集时，用真线程（ext-parallel / ZTS）或
独立进程并行，绕开 PHP 单线程瓶颈：

```bash
composer require kode/parallel
```

```php
use Kode\Scheduling\Runner\ParallelRunner;

$scheduler->setRunner(new ParallelRunner(
    concurrency: 8,        // 并发度；0 = 按 CPU 自动
    engine: 'parallel',    // 'parallel'（真线程，需 ZTS）/ 'process'（多进程）
    bootstrap: null,       // 进程引擎下用于预加载类/函数的引导文件
));
$scheduler->run();
```

**约束（由 kode/parallel 的执行模型决定）：**
1. 任务回调必须可跨边界传递——进程引擎需可序列化、parallel 引擎其捕获变量也需可序列化；
   推荐「顶层命名函数 / 可调用类 / 不捕获外部状态的闭包」。
2. 回调在隔离单元中执行，**不会收到 `Task` 实例作为首参**，也不走 `Task` 内文件锁；
   跨节点互斥交给 `ProcessMutex` + `LeaderCoordinator`。
3. 异常在 worker 内被捕获并序列化为「类别 + 消息」带回主线程（原始堆栈在 worker 侧可见）。

### 模式三：多节点分布式（process）

多台机器部署同一份调度配置时，要保证「同一时刻只有一个节点真正跑某任务」。
用 **kode/process** 提供两样东西：

- **Leader 选举（Coordinator）**：集群中只有 Leader 节点派发任务，其余空转；Leader 崩溃后
  租约（ttl）内自动竞选上位，高可用。
- **分布式锁（Mutex）**：即便 Leader 切换瞬间出现两个节点都以为自己是 Leader，单任务锁仍
  保证该任务只被一个节点抢到执行权——双保险，绝不双跑。

```bash
composer require kode/process
```

```php
use Kode\Process\Cluster;
use Kode\Scheduling\Coordinator\LeaderCoordinator;
use Kode\Scheduling\Mutex\ProcessMutex;

// 可选：指定共享存储后端（redis 跨节点最优；不指定则自动择优 redis→globaldata→file）
Cluster::make('redis', ['host' => '127.0.0.1', 'port' => 6379]);

$scheduler->setMutex(new ProcessMutex());          // 跨节点互斥
$scheduler->setCoordinator(new LeaderCoordinator()); // 仅 Leader 派发
$scheduler->run();
```

部署建议：每台机器都以 `crontab` 每分钟触发 `scheduler run --distributed`，由 Leader
选举自然只让一个节点干活。

> **三者可组合**：`Runner`(并发) + `ProcessMutex`(分布式互斥) + `LeaderCoordinator`(集群派发)
> 可以同时开启——既在单节点内并发，又在多节点间互斥。

---

## 任务配置速查

| 方法 | 说明 |
|------|------|
| `cron('分 时 日 月 周')` | 原始 5 段表达式或 `@hourly` 等宏 |
| `everyMinute()` / `everyFiveMinutes()` … | 常见频率 |
| `hourly()` / `hourlyAt(30)` | 整点 / 第 30 分 |
| `daily()` / `dailyAt('03:30')` | 每天 / 每天指定时刻 |
| `at('09:00','18:00')` | 每天多个时刻 |
| `weekly()` / `monthly()` / `yearly()` | 周期边界 |
| `weekdays()` / `mondays()` … | 按星期限定 |
| `timezone('Asia/Shanghai')` | 任务自身时区 |
| `withoutOverlapping()` / `overlapTtl(60)` | 防重叠 + 锁时长（秒） |
| `between('09:00','18:00')` | 仅窗口内执行（支持跨午夜） |
| `unlessBetween(...)` | 仅窗口外执行 |
| `when(fn => bool)` / `skipWhen(fn => bool)` | 运行时条件 |
| `retry(3, 200)` | 失败重试 3 次，间隔 200ms |
| `before(fn)` / `after(fn)` | 执行前后钩子 |
| `environments('prod')` | 限定环境 |
| `description('说明')` | 可读描述（日志用） |

---

## 调度器 API

| 方法 | 说明 |
|------|------|
| `call(name, callable)` | 注册具名任务 |
| `task(callable, ?name)` | 注册匿名任务 |
| `command('shell', ?name)` | 注册 shell 命令任务 |
| `timezone()` / `environment()` | 基准时区 / 当前环境 |
| `onError(fn)` / `stopOnError()` | 错误处理策略 |
| `beforeRun(fn)` / `afterRun(fn)` | 整轮前后钩子 |
| `setRunner()` / `setMutex()` / `setCoordinator()` | 注入可替换组件 |
| `dueTasks(now)` | 查询到期任务（不发执行） |
| `run(?now)` | 执行，返回 `RunReport` |
| `keepAlive(秒)` | 常驻守护循环（信号优雅退出） |

`RunReport`：`ranAt()`、`succeededCount()`、`failedCount()`、`skippedCount()`、
`successes()`、`failures()`、`skipped()`、`wasDispatched()`、`allSucceeded()`。

---

## CLI 命令行

`bin/scheduler` 支持 `run` / `list` / `next`，约定 `schedule.php` 返回 `Scheduler` 实例：

```php
<?php
use Kode\Scheduling\Scheduler;
$s = new Scheduler();
$s->call('备份', fn () => backup())->dailyAt('02:00');
return $s;
```

```bash
# 默认单机同步执行
php bin/scheduler run --schedule=schedule.php --env=prod --tz=Asia/Shanghai

# 协程并发执行
php bin/scheduler run --runner=fibers

# 并行执行（8 并发，真线程引擎）
php bin/scheduler run --runner=parallel --concurrency=8 --engine=parallel

# 分布式：ProcessMutex + LeaderCoordinator（需 kode/process）
php bin/scheduler run --distributed --backend=redis

# 列出任务 / 查看下次运行时刻
php bin/scheduler list
php bin/scheduler next
```

---

## 由 crontab 驱动

框架本身不含常驻定时器（保持轻量），通常由系统 crontab 每分钟唤醒一次：

```cron
* * * * * php /path/to/worker.php >> /var/log/scheduling.log 2>&1
```

`worker.php` 内只需 `require 'vendor/autoload.php'; $scheduler->run();`（或 `keepAlive()` 常驻）。
分钟级粒度足够覆盖绝大多数 cron 场景；如需秒级，用 `keepAlive(1)`。

---

## 设计原则与健壮性

- **解耦优于耦合**：派发 / 执行 / 互斥三者都是契约，可独立替换、可单测。
- **失败隔离**：单任务异常不中断其他任务；`RunReport` 完整记录成败与跳过原因。
- **优雅降级**：kode/* 均为 `suggest`（可选依赖）；未安装时对应适配器实例化会抛出
  清晰的中文错误，核心单机能力始终可用。
- **防双跑双保险**：`withoutOverlapping()`（单节点）+ `ProcessMutex`（跨节点）。
- **高可用**：`LeaderCoordinator` 保证集群中永远恰有一个节点派发，失联自动切换。
- **可观测**：`RunReport` + `Task::getDescription()` + 钩子，便于接日志/监控。

---

## 常见问题

**Q：不装 kode/* 能用吗？**
能。默认就是单机同步模式，零扩展依赖。三个包都是 `suggest`，按需安装。

**Q：FibersRunner 和 ParallelRunner 怎么选？**
I/O 等待多（HTTP/DB/队列）→ FibersRunner（协程，低开销）；
CPU 密集（计算/编码）→ ParallelRunner（真线程/进程，需 ZTS 或多进程）。

**Q：分布式下 Leader 切换瞬间会双跑吗？**
不会。即使两个节点短暂都自认 Leader，单任务的 `ProcessMutex` 分布式锁仍保证
只有一个节点拿到执行权。

**Q：parallel 模式为什么收不到 Task 实例？**
因为回调在隔离的线程/进程中执行，`Task` 对象不可跨边界。请让回调不依赖 `$task`
参数（或使用 `SyncRunner`/`FibersRunner`，它们共享内存、会传入 `Task`）。

**Q：版本怎么管理？**
包版本由 git tag 提供（`v1.2.0` 等），`composer.json` 不写死 `version` 字段。

---

## 许可证

MIT © Kode
