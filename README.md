# kode/scheduling

通用 Cron 调度库（**PHP 8.3+**）。支持任意 `callable` / `task` / `command`，命名简短无冲突，
健壮可扩展，**完全自包含、零外部依赖**——不绑定任何第三方调度/并发/分布式包。

> 仓库：`https://github.com/kodephp/scheduling` ｜ 许可：MIT ｜ 最低版本：PHP 8.3

---

## 目录

- [特性](#特性)
- [安装](#安装)
- [30 秒上手](#30-秒上手)
- [为什么只到「秒」不加「毫秒」](#为什么只到秒不加毫秒)
- [任务配置速查](#任务配置速查)
- [增强能力：标签 / 回调 / 启停 / 日志](#增强能力标签--回调--启停--日志)
- [调度器 API](#调度器-api)
- [可替换的执行组件（扩展点）](#可替换的执行组件扩展点)
- [CLI 命令行](#cli-命令行)
- [由 crontab 或守护进程驱动](#由-crontab-或守护进程驱动)
- [设计原则与健壮性](#设计原则与健壮性)
- [常见问题](#常见问题)

---

## 特性

- **任意任务**：闭包、函数名、对象方法、可调用对象、`shell` 命令皆可。
- **标准 Cron + 秒级**：5 段（分精度）/ 6 段（秒精度，Quartz 风格 `[秒] [分] [时] [日] [月] [周]`），
  `@hourly/@daily` 等宏，支持范围/列表/步进、月周日英文名、Vixie cron 的「日/周 OR」语义；
  `describe()` 输出中文可读描述，`nextRun()` 推算下次时刻。
- **流畅配置**：`dailyAt()`、`everyFiveMinutes()`、`everySeconds()`、`weekdays()`、`between()`、
  `retry()`、`withoutOverlapping()`、`when()`、`environments()` 等链式调用。
- **标签与分组**：`tag()` 给任务打标签，`run(tag: 'backup')` 按标签批量运行。
- **生命周期回调**：`before()`/`after()`、`onSuccess()`/`onFailure()`、`when()`/`skipWhen()`。
- **启用开关**：`enabled(false)` 临时停用某任务，不影响其余。
- **轻量日志**：内置 `NullLogger` / `SimpleLogger`，注入任意 `LoggerInterface` 即可观测运行过程。
- **逐任务隔离**：单任务失败不中断其余任务；全局 `onError()` / `stopOnError()`。
- **结构化报告**：每次运行产出 `RunReport`（成功/失败/跳过明细）。
- **守护模式**：`keepAlive()` 常驻循环，信号优雅退出；检测到秒级任务时自动提升轮询精度到每秒。
- **可替换组件**：`RunnerInterface` / `MutexInterface` / `CoordinatorInterface` 三大扩展点，
  业务代码零改动即可替换执行模型、互斥后端、派发裁决（如自行接入 Redis）。

---

## 安装

```bash
composer require kode/scheduling
```

---

## 30 秒上手

```php
<?php
require 'vendor/autoload.php';

use Kode\Scheduling\Scheduler;

$scheduler = new Scheduler();
$scheduler->timezone('Asia/Shanghai')->environment('prod');

// 任意 callable：每天 02:00 备份
$scheduler->call('备份数据库', fn () => backup_db())
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->tag('db');

// 工作日 09:00 发日报
$scheduler->call('日报', fn () => send_report())
    ->weekdays()->at('09:00')
    ->tag('report');

// 原生 6 段 cron：工作日 9-18 点每 15 秒一次同步
$scheduler->task(fn () => sync(), '同步')
    ->cron('*/15 9-18 * * 1-5');

// 由系统 crontab 每分钟触发本进程（或 keepAlive 守护）
$report = $scheduler->run();
// $report->succeededCount() / failedCount() / skipped()
```

crontab 只需一行驱动：

```cron
* * * * * php /path/to/worker.php
```

---

## 为什么只到「秒」不加「毫秒」

本库是**墙钟（wall-clock）定时调度**：由系统 crontab 每分钟触发，或常驻 `keepAlive` 循环驱动。
在这个模型下：

- **秒有意义**：配合 `keepAlive` 守护进程或高频触发器，可实现「每 15 秒」「每天 03:00:30」等需求；
  我们也因此支持 6 段（秒级）表达式。
- **毫秒没意义**：再细到毫秒需要密集轮询、徒增 CPU 开销，而 PHP 又非实时系统，毫秒级触发
  本就不精确，且会拖垮调度进程。最小实用的调度粒度就是秒。

因此本库最高支持到**秒**，主动放弃毫秒。若确有亚秒级实时需求，应改用消息队列/事件循环（如 Swoole、ReactPHP），而非 cron 类库。

---

## 任务配置速查

| 方法 | 表达式示例 | 含义 |
|------|-----------|------|
| `cron('30 3 * * *')` | `30 3 * * *` | 每天 03:30 |
| `cron('*/15 * * * * *')` | `*/15 * * * * *` | 每 15 秒 |
| `everyMinute()` | `* * * * *` | 每分钟 |
| `everyFiveMinutes()` | `*/5 * * * *` | 每 5 分钟 |
| `everySecond()` | `*/1 * * * * *` | 每秒 |
| `everySeconds(30)` | `*/30 * * * * *` | 每 30 秒 |
| `hourly()` / `hourlyAt(15)` | `0 * * * *` / `15 * * * *` | 整点 / 每小时第 15 分 |
| `daily()` / `dailyAt('03:30')` | `0 0 * * *` / `30 3 * * *` | 每天零点 / 每天 03:30 |
| `at('01:00', '13:30')` | `0 1,13 * * *` | 每天 1 点、13 点 |
| `weekdays()` / `weekends()` | `1-5` / `0,6` | 工作日 / 周末 |
| `mondays()` ... `sundays()` | `1` ... `0` | 指定星期 |
| `monthly()` / `quarterly()` / `yearly()` | `0 0 1 * *` 等 | 月/季/年初 |
| `between('09:00','18:00')` | — | 限定执行时间窗口 |
| `when(fn)` / `skipWhen(fn)` | — | 运行时条件判定 |
| `withoutOverlapping()` | — | 防重叠（互斥锁） |
| `retry(3, 200)` | — | 失败重试 3 次，间隔 200ms |
| `tag('db')` / `enabled(false)` | — | 标签 / 停用 |

---

## 增强能力：标签 / 回调 / 启停 / 日志

```php
$scheduler->call('订单同步', function () { /* ... */ })
    ->cron('*/10 * * * * *')          // 每 10 秒
    ->tag('order', 'realtime')        // 可打多个标签
    ->when(fn ($task) => rand(0,1) > 0)   // 前置条件
    ->onSuccess(fn ($result, $task) => log_ok($result))
    ->onFailure(fn (\Throwable $e, $task) => alert($e))
    ->enabled(true);                  // 临时停用：enabled(false)

// 仅运行带 'order' 标签的任务
$report = $scheduler->run(tag: 'order');

// 注入日志器观察运行过程（SimpleLogger 输出到 STDOUT/STDERR）
$scheduler->setLogger(new \Kode\Scheduling\Logger\SimpleLogger());
```

`describe()` 与 `nextRun()` 始终可用，便于 UI/监控展示：

```php
foreach ($scheduler->tasks() as $task) {
    echo $task->name(), ' → ', $task->cron()->describe(),
         ' (下次 ', $task->nextRun(new DateTimeImmutable())?->format('Y-m-d H:i:s'), ")\n";
}
```

---

## 调度器 API

| 方法 | 说明 |
|------|------|
| `call(name, callable)` | 注册具名任务，返回 `Task` 可继续配置 |
| `task(callable, ?name)` | 注册匿名任务（按回调指纹自动命名） |
| `command('shell', ?name)` | 注册 shell 命令任务 |
| `timezone()` / `environment()` | 调度器时区 / 当前环境（与 `environments()` 比对） |
| `onError(Closure)` / `stopOnError()` | 全局错误处理器 / 遇错即停 |
| `beforeRun()` / `afterRun()` | 整轮运行前后钩子 |
| `setRunner()` / `setMutex()` / `setCoordinator()` | 替换扩展组件 |
| `setLogger()` | 注入日志器 |
| `run(?now, ?tags)` | 执行到期（或指定标签）任务，返回 `RunReport` |
| `dueTasks(now, ?tags)` | 查询到期任务（不执行） |
| `keepAlive(interval)` | 守护模式常驻循环 |
| `tasks()` / `find(name)` | 任务列表 / 按名查找 |

`RunReport`：`ranAt()`、`successes()`、`failures()`、`skipped()`、`succeededCount()`、
`failedCount()`、`skippedCount()`、`totalRun()`、`allSucceeded()`、`wasDispatched()`。

---

## 可替换的执行组件（扩展点）

三大契约全部内置默认实现，**零依赖**：

| 契约 | 默认实现 | 作用 |
|------|----------|------|
| `RunnerInterface` | `SyncRunner` | 顺序同步执行任务 |
| `MutexInterface` | `FileMutex` | 本地文件锁（flock）防重入 |
| `CoordinatorInterface` | `LocalCoordinator` | 恒派发（单机） |

如需 Redis 锁、集群 Leader 选举、并发执行等，自行实现对应接口并通过
`setMutex()` / `setCoordinator()` / `setRunner()` 注入即可——**框架不绑定任何特定外部包**，
你完全掌控依赖。例如一个最简单的自定义 Runner：

```php
use Kode\Scheduling\Contract\RunnerInterface;
use Kode\Scheduling\Task;
use Kode\Scheduling\TaskOutcome;
use Kode\Scheduling\TaskStatus;

final class MyRunner implements RunnerInterface
{
    #[\Override]
    public function runAll(array $tasks, \DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($tasks as $task) {
            try {
                $out[] = new TaskOutcome($task->name(), TaskStatus::Success, $task->run($now));
            } catch (\Throwable $e) {
                $out[] = new TaskOutcome($task->name(), TaskStatus::Error, error: $e);
            }
        }
        return $out;
    }
}
$scheduler->setRunner(new MyRunner());
```

---

## CLI 命令行

```bash
# 执行到期任务
php bin/scheduler run  [--schedule=文件] [--env=环境] [--tz=时区] [--tag=标签] [--verbose]

# 列出全部（或指定标签）任务
php bin/scheduler list [--schedule=文件] [--tz=时区] [--tag=标签]

# 显示各任务下次运行时刻
php bin/scheduler next [--schedule=文件] [--tz=时区]
```

`schedule` 文件约定返回一个 `Scheduler` 实例：

```php
<?php
use Kode\Scheduling\Scheduler;
$s = new Scheduler();
$s->call('备份', fn () => backup())->dailyAt('02:00');
return $s;
```

`--verbose` 会启用 `SimpleLogger`，把调度开始/成功/失败/结束打印到控制台；`--tag=db`
只运行带 `db` 标签的任务。

---

## 由 crontab 或守护进程驱动

**方式一：crontab 每分钟触发**（最省心，适合分钟级任务）：

```cron
* * * * * php /path/to/worker.php
```

**方式二：常驻守护进程**（`keepAlive`，适合秒级任务，或不想依赖系统 crontab）：

```php
$scheduler->keepAlive(60); // 每 60 秒循环；若注册了秒级任务会自动降为每秒
```

收到 `SIGINT`/`SIGTERM`（pcntl 可用时）会优雅停止。建议用 `nohup` 或 `supervisor` 托管。

> 注意：秒级任务若仍由「每分钟」的 crontab 驱动，则只能在整分钟那一秒被触发（如 `0 * * * * *`
恰好命中）。要真正每秒/每 N 秒执行，请用 `keepAlive` 守护或更高频的触发器。

---

## 设计原则与健壮性

- **异常隔离**：单个任务抛错默认不中断其余任务，统一进入 `RunReport` 与 `onError()`；
  需要「遇错即停」时调用 `stopOnError()`。
- **条件短路**：`when()`/`skipWhen()`/`between()`/`environments()`/`enabled()` 在运行前判定，
  不满足的任务记为「跳过」且不占用执行资源。
- **防重叠**：`withoutOverlapping()` 配合 `MutexInterface` 保证同一任务同一时刻只有一个执行单元在跑；
  锁 TTL 应大于任务预期耗时，避免提前过期导致双跑。
- **时区清晰**：cron 字段始终按任务自身时区解释；`nextRun()`/`describe()` 同样遵循该时区。
- **不可变结果**：`TaskOutcome` 用只读属性与 `TaskStatus` 枚举承载终态，跨执行模型也安全。
- **零依赖**：仅依赖 PHP 8.3 标准库，不引入任何 `composer` 额外依赖。

---

## 常见问题

**Q：毫秒精度能做吗？** 不支持，且不建议（见上文「为什么只到秒」）。亚秒级实时请改用事件循环。

**Q：能分布式多节点部署吗？** 可以，但不内置特定实现以保持零依赖。自行实现
`MutexInterface`（如 Redis 锁）与 `CoordinatorInterface`（如基于 Redis 的 Leader 选举）后注入即可。

**Q：和 Laravel/A金融 调度有什么区别？** 本库是独立、轻量的纯 PHP 库，无框架依赖，可嵌入任意项目；
  关注点只是「按需触发任意 callable」并给出清晰的运行报告。

**Q：Cron 表达式某天同时满足「日」和「周」怎么办？** 遵循 Vixie cron 的「或」规则——满足其一即命中
（如 `0 0 1 * 1` = 每月 1 号 **或** 每周一）。

**Q：如何在测试里运行？** `run(new DateTimeImmutable('2026-08-12 10:00:00'))` 传入基准时刻即可确定性执行，
便于断言。
