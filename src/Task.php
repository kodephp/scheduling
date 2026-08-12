<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Contract\MutexInterface;
use Kode\Scheduling\Exception\TaskError;
use Kode\Scheduling\Mutex\FileMutex;

/**
 * 任务封装：把“任意 callable”包装成一个可被调度器识别、可流畅配置的执行单元。
 *
 * 典型用法：
 * <code>
 *   $scheduler->call('清理缓存', fn () => cleanup())
 *       ->dailyAt('03:00')
 *       ->withoutOverlapping();
 * </code>
 *
 * 设计要点：
 *  - 时区：cron 字段始终按任务自身时区解释；
 *  - 精度：支持分钟级（5 段）与秒级（6 段，Quartz 风格）两种表达式；
 *  - 防重叠：可开启文件锁，避免同一任务并发重入；
 *  - 条件执行：when()/skipWhen() 在运行时决定是否真正执行；
 *  - 生命周期钩子：before()/after()/onSuccess()/onFailure() 分别在执行前后触发。
 */
final class Task
{
    /** 当前 cron 字段：5 段（分钟级）或 6 段（秒级）。 */
    private array $segments = ['*', '*', '*', '*', '*'];

    /** 是否包含“秒”字段（6 段）。分钟级工具方法会将其重置为 false。 */
    private bool $hasSeconds = false;

    /** 由 segments 构建的 Cron 对象缓存（字段变更后失效重建）。 */
    private ?Cron $cron = null;

    private ?\DateTimeZone $timezone = null;

    /** 是否开启防重叠（互斥锁）。 */
    private bool $overlapping = false;

    /** 互斥锁逻辑键；为 null 时按任务名自动生成。 */
    private ?string $lockKey = null;

    /** 防重叠锁存活时长（秒）；必须大于任务预期耗时，避免锁提前过期导致双跑。 */
    private float $overlapTtlSeconds = 30.0;

    /** 防重叠用的互斥锁实现；为 null 时由 Scheduler 注入，或惰性回退到默认文件锁。 */
    private ?MutexInterface $mutex = null;

    /** 允许执行的环境列表；为空表示不限环境。 */
    private array $environments = [];

    /** 前置条件：返回 false 则本次不执行。 */
    private array $whens = [];

    /** 跳过条件：返回 true 则本次不执行。 */
    private array $skipWhens = [];

    /** 执行前钩子。 */
    private array $befores = [];

    /** 执行后钩子（无论成功失败都会触发，参数为任务与异常或 null）。 */
    private array $afters = [];

    /** 最近一次执行结果/异常/时间，便于事后观测。 */
    private mixed $lastResult = null;
    private ?\Throwable $lastError = null;
    private ?\DateTimeImmutable $lastRanAt = null;
    private bool $lastSuccess = false;

    /** 最近一次被跳过的原因：null=未跳过；'condition'=条件不满足；'overlap'=锁冲突。 */
    private ?string $lastSkipReason = null;

    /** 任务可读描述（仅用于展示/日志，不影响调度）。 */
    private ?string $description = null;

    /** 执行时间窗口（H:i 格式）；为 null 表示不限制。 */
    private ?string $windowStart = null;
    private ?string $windowEnd = null;

    /** 时间窗口语义：false=区间内执行；true=区间外执行（unlessBetween）。 */
    private bool $unlessWindow = false;

    /** 失败后重试次数（不含首次）。 */
    private int $retryTimes = 0;

    /** 重试间隔（毫秒）。 */
    private int $retryDelayMs = 0;

    /** 标签：用于按标签批量运行（如 run(tag: 'backup')）。 */
    private array $tags = [];

    /** 是否启用；false 时永远不参与调度（便于临时停用）。 */
    private bool $enabled = true;

    /** 成功回调（执行成功且重试后仍成功时触发）。 */
    private array $onSuccesses = [];

    /** 失败回调（重试耗尽后仍失败时触发，参数为异常）。 */
    private array $onFailures = [];

    /**
     * @param string   $name     任务唯一名称（用于锁、日志、报告）
     * @param callable $callback 任意可调用对象；执行时会把本 Task 实例作为首参传入
     */
    public function __construct(
        private string $name,
        private $callback
    ) {
        if (!\is_callable($callback)) {
            throw TaskError::for($name, 'callback 必须是可调用类型');
        }
    }

    /** 任务名称。 */
    public function name(): string
    {
        return $this->name;
    }

    /** 当前 cron 表达式字符串。 */
    public function expression(): string
    {
        return \implode(' ', $this->segments);
    }

    // ------------------------------------------------------------------
    // 频率配置（流畅方法，均返回 $this）
    // ------------------------------------------------------------------

    /** 使用原始 5 段或 6 段 cron 表达式（或 @宏）。 */
    public function cron(string $expression): static
    {
        $parts = \preg_split('/\s+/', \trim($expression));
        $n = $parts === false ? 0 : \count($parts);
        if ($n !== 5 && $n !== 6) {
            throw TaskError::for(
                $this->name,
                'cron() 需传入 5 段表达式（分 时 日 月 周）或 6 段表达式（秒 分 时 日 月 周）'
            );
        }
        $this->hasSeconds = ($n === 6);
        $this->segments = $parts;
        $this->cron = null;

        return $this;
    }

    /** 每分钟。 */
    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    /** 每 5 分钟。 */
    public function everyFiveMinutes(): static
    {
        return $this->cron('*/5 * * * *');
    }

    /** 每 10 分钟。 */
    public function everyTenMinutes(): static
    {
        return $this->cron('*/10 * * * *');
    }

    /** 每 15 分钟。 */
    public function everyFifteenMinutes(): static
    {
        return $this->cron('*/15 * * * *');
    }

    /** 每 30 分钟。 */
    public function everyThirtyMinutes(): static
    {
        return $this->cron('*/30 * * * *');
    }

    /** 每小时（整点）。 */
    public function hourly(): static
    {
        return $this->cron('0 * * * *');
    }

    /** 每小时的第 $minute 分钟。 */
    public function hourlyAt(int $minute): static
    {
        return $this->cron(\sprintf('%d * * * *', $minute));
    }

    /** 每秒（秒级：每 1 秒触发一次，等价于 6 段表达式 "星号除1 ..."）。 */
    public function everySecond(): static
    {
        return $this->cron('*/1 * * * * *');
    }

    /** 每 $n 秒（秒级）。 */
    public function everySeconds(int $n): static
    {
        if ($n < 1 || $n > 59) {
            throw TaskError::for($this->name, 'everySeconds() 的间隔需为 1-59 秒');
        }

        return $this->cron(\sprintf('*/%d * * * * *', $n));
    }

    /** 显式指定“秒”字段（自动将表达式升级为 6 段秒级）。 */
    public function second(int $second): static
    {
        if ($second < 0 || $second > 59) {
            throw TaskError::for($this->name, 'second() 需为 0-59');
        }
        if (!$this->hasSeconds) {
            $this->segments = \array_merge(['*'], $this->segments);
            $this->hasSeconds = true;
        }
        $this->segments[0] = (string) $second;
        $this->cron = null;

        return $this;
    }

    /** 每天零点。 */
    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    /** 每天指定时刻，如 dailyAt('03:30')（分钟级，重置为 5 段）。 */
    public function dailyAt(string $time): static
    {
        $this->resetToMinutePrecision();
        [$m, $h] = $this->parseTime($time);
        $this->setSegment($this->idxMinute(), $m);
        $this->setSegment($this->idxHour(), $h);

        return $this;
    }

    /** 每天多个指定时刻，如 at('01:00', '13:30')（分钟级，重置为 5 段）。 */
    public function at(string ...$times): static
    {
        $this->resetToMinutePrecision();
        $minutes = [];
        $hours = [];
        foreach ($times as $time) {
            [$m, $h] = $this->parseTime($time);
            $minutes[] = $m;
            $hours[] = $h;
        }
        $this->setSegment($this->idxMinute(), \implode(',', \array_unique($minutes)));
        $this->setSegment($this->idxHour(), \implode(',', \array_unique($hours)));

        return $this;
    }

    /** 每天两次（默认 01:00 与 13:00）（分钟级，重置为 5 段）。 */
    public function twiceDaily(int $hour1 = 1, int $hour2 = 13): static
    {
        $this->resetToMinutePrecision();
        $this->setSegment($this->idxMinute(), '0');
        $this->setSegment($this->idxHour(), \sprintf('%d,%d', $hour1, $hour2));

        return $this;
    }

    /** 每周日零点。 */
    public function weekly(): static
    {
        return $this->cron('0 0 * * 0');
    }

    /** 每月 1 号零点。 */
    public function monthly(): static
    {
        return $this->cron('0 0 1 * *');
    }

    /** 每季度首月 1 号零点。 */
    public function quarterly(): static
    {
        return $this->cron('0 0 1 1,4,7,10 *');
    }

    /** 每年 1 月 1 号零点。 */
    public function yearly(): static
    {
        return $this->cron('0 0 1 1 *');
    }

    /** 工作日（周一至周五）执行，保留当前时分设置。 */
    public function weekdays(): static
    {
        return $this->setSegment($this->idxWeekday(), '1-5');
    }

    /** 周末（周六、周日）执行。 */
    public function weekends(): static
    {
        return $this->setSegment($this->idxWeekday(), '0,6');
    }

    /** 周一执行。 */
    public function mondays(): static
    {
        return $this->setSegment($this->idxWeekday(), '1');
    }

    /** 周二执行。 */
    public function tuesdays(): static
    {
        return $this->setSegment($this->idxWeekday(), '2');
    }

    /** 周三执行。 */
    public function wednesdays(): static
    {
        return $this->setSegment($this->idxWeekday(), '3');
    }

    /** 周四执行。 */
    public function thursdays(): static
    {
        return $this->setSegment($this->idxWeekday(), '4');
    }

    /** 周五执行。 */
    public function fridays(): static
    {
        return $this->setSegment($this->idxWeekday(), '5');
    }

    /** 周六执行。 */
    public function saturdays(): static
    {
        return $this->setSegment($this->idxWeekday(), '6');
    }

    /** 周日执行。 */
    public function sundays(): static
    {
        return $this->setSegment($this->idxWeekday(), '0');
    }

    // ------------------------------------------------------------------
    // 运行期配置
    // ------------------------------------------------------------------

    /** 指定任务时区（cron 字段按此时区解释）。 */
    public function timezone(\DateTimeZone|string $timezone): static
    {
        $this->timezone = \is_string($timezone) ? new \DateTimeZone($timezone) : $timezone;

        return $this;
    }

    /** 开启防重叠；可选自定义锁逻辑键（分布式锁名 / 文件锁标识）。 */
    public function withoutOverlapping(?string $key = null): static
    {
        $this->overlapping = true;
        if ($key !== null) {
            $this->lockKey = $key;
        }

        return $this;
    }

    /** 设置防重叠锁的存活时长（秒）；大于任务预期耗时可避免锁提前过期。 */
    public function overlapTtl(float $seconds): static
    {
        if ($seconds <= 0) {
            throw TaskError::for($this->name, 'overlapTtl() 必须为正数');
        }
        $this->overlapTtlSeconds = $seconds;

        return $this;
    }

    /** 注入互斥锁实现（通常由 Scheduler 在注册任务时自动完成）。 */
    public function setMutex(MutexInterface $mutex): static
    {
        $this->mutex = $mutex;

        return $this;
    }

    /** 返回任务回调（供并行执行器在隔离单元中调用）。 */
    public function callback(): callable
    {
        return $this->callback;
    }

    /** 限定仅在指定环境运行（与 Scheduler 的当前环境比对）。 */
    public function environments(string|array $envs): static
    {
        $this->environments = (array) $envs;

        return $this;
    }

    /** 前置条件：回调返回 false 则跳过本次执行。 */
    public function when(callable $callback): static
    {
        $this->whens[] = $callback;

        return $this;
    }

    /** 跳过条件：回调返回 true 则跳过本次执行。 */
    public function skipWhen(callable $callback): static
    {
        $this->skipWhens[] = $callback;

        return $this;
    }

    /** 执行前钩子。 */
    public function before(callable $callback): static
    {
        $this->befores[] = $callback;

        return $this;
    }

    /** 执行后钩子（无论成败都会触发，第二参数为异常或 null）。 */
    public function after(callable $callback): static
    {
        $this->afters[] = $callback;

        return $this;
    }

    /** 设置任务的可读描述（用于日志/展示）。 */
    public function description(string $text): static
    {
        $this->description = $text;

        return $this;
    }

    /** 限定仅在每日时间窗口 [from, to] 内执行（如 between('09:00', '18:00')）。 */
    public function between(string $from, string $to): static
    {
        $this->windowStart = $this->normalizeHi($from);
        $this->windowEnd = $this->normalizeHi($to);
        $this->unlessWindow = false;

        return $this;
    }

    /** 限定“仅不在”每日时间窗口 [from, to] 内执行（区间外才跑）。 */
    public function unlessBetween(string $from, string $to): static
    {
        $this->between($from, $to);
        $this->unlessWindow = true;

        return $this;
    }

    /** 设置失败重试：最多重试 $times 次，每次间隔 $delayMs 毫秒。 */
    public function retry(int $times, int $delayMs = 0): static
    {
        if ($times < 0) {
            throw TaskError::for($this->name, 'retry() 次数不能为负');
        }
        if ($delayMs < 0) {
            throw TaskError::for($this->name, 'retry() 间隔不能为负');
        }
        $this->retryTimes = $times;
        $this->retryDelayMs = $delayMs;

        return $this;
    }

    /** 打标签（可多次调用累加），便于按标签运行或分组管理。 */
    public function tag(string|array $tags): static
    {
        foreach ((array) $tags as $t) {
            $this->tags[] = $t;
        }

        return $this;
    }

    /** 启用/停用本任务（停用后永远不参与调度）。 */
    public function enabled(bool $enabled = true): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    /** 注册“成功回调”：任务执行成功（含重试后成功）后触发，参数为返回值。 */
    public function onSuccess(callable $callback): static
    {
        $this->onSuccesses[] = $callback;

        return $this;
    }

    /** 注册“失败回调”：重试耗尽仍失败时触发，参数为异常。 */
    public function onFailure(callable $callback): static
    {
        $this->onFailures[] = $callback;

        return $this;
    }

    // ------------------------------------------------------------------
    // 判定与执行
    // ------------------------------------------------------------------

    /**
     * 判断在 $now 时刻是否“到期”（仅看时间，不含条件/环境）。
     */
    public function isDue(\DateTimeImmutable $now): bool
    {
        $check = $this->timezone === null ? $now : $now->setTimezone($this->timezone);

        return $this->cronObject()->isDue($check);
    }

    /**
     * 计算从 $from 起的下一个到期时刻（按任务时区）。
     */
    public function nextRun(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        try {
            $check = $this->timezone === null ? $from : $from->setTimezone($this->timezone);

            return $this->cronObject()->nextRun($check);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 综合判定：是否应当执行（到期 + 环境 + 时间窗口 + 条件）。
     *
     * @param string $environment Scheduler 当前环境，用于 environments() 比对
     */
    public function shouldRun(\DateTimeImmutable $now, string $environment = ''): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if (!$this->isDue($now)) {
            return false;
        }
        if ($this->environments !== [] && $environment !== '' && !\in_array($environment, $this->environments, true)) {
            return false;
        }
        $taskNow = $this->timezone === null ? $now : $now->setTimezone($this->timezone);
        if (!$this->inTimeWindow($taskNow)) {
            return false;
        }

        return $this->allowedByConditions();
    }

    /**
     * 执行任务。
     *
     * @param \DateTimeImmutable|null $now 基准时刻（用于时间窗口判定与记录）；
     *                                     为 null 时取当前时刻（任务时区）。
     * @return mixed 正常返回回调结果；被跳过（条件/窗口/锁）返回 null；回调最终仍失败会向上抛出
     */
    public function run(?\DateTimeImmutable $now = null): mixed
    {
        $now ??= new \DateTimeImmutable('now', $this->timezone ?? new \DateTimeZone(\date_default_timezone_get()));

        if (!$this->allowedByConditions() || !$this->inTimeWindow($now)) {
            $this->lastSkipReason = 'condition';

            return null;
        }

        foreach ($this->befores as $cb) {
            $cb($this);
        }

        $lock = null;
        if ($this->overlapping) {
            $mutex = $this->mutex ??= new FileMutex();
            $key = $this->lockKey();
            if (!$mutex->acquire($key, \max(1.0, $this->overlapTtlSeconds()))) {
                $this->lastSkipReason = 'overlap';

                return null; // 已有实例在运行，本次跳过
            }
            $lock = $key; // 标记已持锁，finally 中据此释放
        }
        $this->lastSkipReason = null;

        $this->lastRanAt = $now;
        try {
            $result = $this->invokeWithRetry();
            $this->lastResult = $result;
            $this->lastError = null;
            $this->lastSuccess = true;
            foreach ($this->onSuccesses as $cb) {
                try {
                    $cb($result, $this);
                } catch (\Throwable) {
                    // 回调异常不应影响主流程
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e;
            $this->lastSuccess = false;
            foreach ($this->onFailures as $cb) {
                try {
                    $cb($e, $this);
                } catch (\Throwable) {
                    // 回调异常不应影响主流程
                }
            }
            throw $e;
        } finally {
            foreach ($this->afters as $cb) {
                try {
                    $cb($this, $this->lastError);
                } catch (\Throwable) {
                    // 钩子异常不应影响主流程
                }
            }
            if ($lock !== null) {
                ($this->mutex ?? new FileMutex())->release($lock);
            }
        }
    }

    // ------------------------------------------------------------------
    // 观测属性
    // ------------------------------------------------------------------

    public function lastResult(): mixed
    {
        return $this->lastResult;
    }

    public function lastError(): ?\Throwable
    {
        return $this->lastError;
    }

    public function lastRanAt(): ?\DateTimeImmutable
    {
        return $this->lastRanAt;
    }

    public function lastSuccess(): bool
    {
        return $this->lastSuccess;
    }

    /** 最近一次被跳过的原因（null / 'condition' / 'overlap'）。 */
    public function lastSkipReason(): ?string
    {
        return $this->lastSkipReason;
    }

    /** 任务可读描述（未设置时返回 null）。 */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /** 是否包含秒字段（6 段）。 */
    public function hasSeconds(): bool
    {
        return $this->hasSeconds;
    }

    /** 任务标签列表。 */
    public function tags(): array
    {
        return $this->tags;
    }

    /** 是否处于启用状态。 */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /** 惰性构建并缓存 Cron 对象。 */
    private function cronObject(): Cron
    {
        if ($this->cron === null) {
            $this->cron = new Cron($this->expression());
        }

        return $this->cron;
    }

    /**
     * 各字段在 segments 数组中的下标（随是否含“秒”字段而整体偏移）。
     * 6 段（秒 分 时 日 月 周）时秒占 0 号位，其余顺延一位。
     */
    private function idxMinute(): int
    {
        return $this->hasSeconds ? 1 : 0;
    }

    private function idxHour(): int
    {
        return $this->hasSeconds ? 2 : 1;
    }

    private function idxDay(): int
    {
        return $this->hasSeconds ? 3 : 2;
    }

    private function idxMonth(): int
    {
        return $this->hasSeconds ? 4 : 3;
    }

    private function idxWeekday(): int
    {
        return $this->hasSeconds ? 5 : 4;
    }

    /** 将表达式重置为分钟级（5 段），供 dailyAt/at/twiceDaily 等工具方法使用。 */
    private function resetToMinutePrecision(): void
    {
        $this->hasSeconds = false;
        $this->segments = ['*', '*', '*', '*', '*'];
    }

    /** 仅修改单段字段，并令 Cron 缓存失效（用于 weekdays() 等组合方法）。 */
    private function setSegment(int $index, string $value): static
    {
        $this->segments[$index] = $value;
        $this->cron = null;

        return $this;
    }

    /** 解析 "H:i" / "Hi" 形式的时间为 [分钟, 小时]。 */
    private function parseTime(string $time): array
    {
        if (\str_contains($time, ':')) {
            [$h, $m] = \explode(':', $time, 2);
        } else {
            $t = \strlen($time) >= 4 ? $time : \str_pad($time, 4, '0', \STR_PAD_LEFT);
            $h = \substr($t, 0, -2);
            $m = \substr($t, -2);
        }
        $h = (int) $h;
        $m = (int) $m;
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw TaskError::for($this->name, \sprintf('非法时刻 "%s"（应为 00:00-23:59）', $time));
        }

        return [(string) $m, (string) $h];
    }

    /** 防重叠锁逻辑键（文件锁会据此映射成文件路径，分布式锁据此命名）。 */
    private function lockKey(): string
    {
        if ($this->lockKey !== null) {
            return $this->lockKey;
        }

        return 'kode:scheduling:overlap:' . \sha1($this->name);
    }

    /** 防重叠锁存活时长（秒）。 */
    private function overlapTtlSeconds(): float
    {
        return $this->overlapTtlSeconds;
    }

    /** 条件钩子是否允许执行。 */
    private function allowedByConditions(): bool
    {
        foreach ($this->whens as $cb) {
            if (!($cb)($this)) {
                return false;
            }
        }
        foreach ($this->skipWhens as $cb) {
            if (($cb)($this)) {
                return false;
            }
        }

        return true;
    }

    /** 判断 $now 是否落在执行时间窗口内（无窗口则恒为 true）。 */
    private function inTimeWindow(\DateTimeImmutable $now): bool
    {
        if ($this->windowStart === null || $this->windowEnd === null) {
            return true;
        }
        $t = $now->format('H:i');
        // 普通区间
        if ($this->windowStart <= $this->windowEnd) {
            $in = $t >= $this->windowStart && $t <= $this->windowEnd;
        } else {
            // 跨午夜区间，如 22:00~06:00
            $in = $t >= $this->windowStart || $t <= $this->windowEnd;
        }

        return $this->unlessWindow ? !$in : $in;
    }

    /** 将 "H:i" / "Hi" 归一化为 "H:i"（两位补零）。 */
    private function normalizeHi(string $time): string
    {
        [$m, $h] = $this->parseTime($time);

        return \sprintf('%02d:%02d', (int) $h, (int) $m);
    }

    /** 带重试地执行回调；全部失败后抛出最后一次异常。 */
    private function invokeWithRetry(): mixed
    {
        $attempts = $this->retryTimes + 1;
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return ($this->callback)($this);
            } catch (\Throwable $e) {
                $last = $e;
                if ($this->retryDelayMs > 0 && $i < $attempts - 1) {
                    \usleep($this->retryDelayMs * 1000);
                }
            }
        }

        throw $last; // 此处 $last 必然非 null
    }
}
