<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Exception\TaskError;

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
 *  - 防重叠：可开启文件锁，避免同一任务并发重入；
 *  - 条件执行：when()/skipWhen() 在运行时决定是否真正执行；
 *  - 生命周期钩子：before()/after() 分别在执行前后触发。
 */
final class Task
{
    /** 5 段 cron 字段的索引：0=分 1=时 2=日 3=月 4=周。 */
    private const MINUTE = 0;
    private const HOUR = 1;
    private const DAY = 2;
    private const MONTH = 3;
    private const WEEKDAY = 4;

    /** 当前 cron 字段（5 段），用于流畅地组合时间限制。 */
    private array $segments = ['*', '*', '*', '*', '*'];

    /** 由 segments 构建的 Cron 对象缓存（字段变更后失效重建）。 */
    private ?Cron $cron = null;

    private ?\DateTimeZone $timezone = null;

    /** 是否开启防重叠（文件锁）。 */
    private bool $overlapping = false;

    /** 自定义锁路径；为 null 时使用默认临时目录。 */
    private ?string $lockPath = null;

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

    /** 使用原始 5 段 cron 表达式（或 @宏）。 */
    public function cron(string $expression): static
    {
        $parts = \preg_split('/\s+/', \trim($expression));
        if ($parts === false || \count($parts) !== 5) {
            throw TaskError::for($this->name, 'cron() 需传入 5 段表达式（分 时 日 月 周）');
        }
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

    /** 每天零点。 */
    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    /** 每天指定时刻，如 dailyAt('03:30')。 */
    public function dailyAt(string $time): static
    {
        [$m, $h] = $this->parseTime($time);
        $this->setSegment(self::MINUTE, $m);
        $this->setSegment(self::HOUR, $h);

        return $this;
    }

    /** 每天多个指定时刻，如 at('01:00', '13:30')。 */
    public function at(string ...$times): static
    {
        $minutes = [];
        $hours = [];
        foreach ($times as $time) {
            [$m, $h] = $this->parseTime($time);
            $minutes[] = $m;
            $hours[] = $h;
        }
        $this->setSegment(self::MINUTE, \implode(',', \array_unique($minutes)));
        $this->setSegment(self::HOUR, \implode(',', \array_unique($hours)));

        return $this;
    }

    /** 每天两次（默认 01:00 与 13:00）。 */
    public function twiceDaily(int $hour1 = 1, int $hour2 = 13): static
    {
        $this->setSegment(self::MINUTE, '0');
        $this->setSegment(self::HOUR, \sprintf('%d,%d', $hour1, $hour2));

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
        return $this->setSegment(self::WEEKDAY, '1-5');
    }

    /** 周末（周六、周日）执行。 */
    public function weekends(): static
    {
        return $this->setSegment(self::WEEKDAY, '0,6');
    }

    /** 周一执行。 */
    public function mondays(): static
    {
        return $this->setSegment(self::WEEKDAY, '1');
    }

    /** 周二执行。 */
    public function tuesdays(): static
    {
        return $this->setSegment(self::WEEKDAY, '2');
    }

    /** 周三执行。 */
    public function wednesdays(): static
    {
        return $this->setSegment(self::WEEKDAY, '3');
    }

    /** 周四执行。 */
    public function thursdays(): static
    {
        return $this->setSegment(self::WEEKDAY, '4');
    }

    /** 周五执行。 */
    public function fridays(): static
    {
        return $this->setSegment(self::WEEKDAY, '5');
    }

    /** 周六执行。 */
    public function saturdays(): static
    {
        return $this->setSegment(self::WEEKDAY, '6');
    }

    /** 周日执行。 */
    public function sundays(): static
    {
        return $this->setSegment(self::WEEKDAY, '0');
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

    /** 开启防重叠；可选自定义锁文件路径。 */
    public function withoutOverlapping(?string $path = null): static
    {
        $this->overlapping = true;
        if ($path !== null) {
            $this->lockPath = $path;
        }

        return $this;
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
     * 综合判定：是否应当执行（到期 + 环境 + 条件）。
     *
     * @param string $environment Scheduler 当前环境，用于 environments() 比对
     */
    public function shouldRun(\DateTimeImmutable $now, string $environment = ''): bool
    {
        if (!$this->isDue($now)) {
            return false;
        }
        if ($this->environments !== [] && $environment !== '' && !\in_array($environment, $this->environments, true)) {
            return false;
        }

        return $this->allowedByConditions();
    }

    /** 执行任务。正常返回回调结果；被跳过（条件/锁）返回 null；回调抛错会向上抛出。 */
    public function run(): mixed
    {
        if (!$this->allowedByConditions()) {
            $this->lastSkipReason = 'condition';

            return null;
        }

        foreach ($this->befores as $cb) {
            $cb($this);
        }

        $lock = null;
        if ($this->overlapping) {
            $lock = new Lock($this->lockPath());
            if (!$lock->acquire()) {
                $this->lastSkipReason = 'overlap';

                return null; // 已有实例在运行，本次跳过
            }
        }
        $this->lastSkipReason = null;

        $this->lastRanAt = new \DateTimeImmutable('now');
        try {
            $result = ($this->callback)($this);
            $this->lastResult = $result;
            $this->lastError = null;
            $this->lastSuccess = true;

            return $result;
        } catch (\Throwable $e) {
            $this->lastError = $e;
            $this->lastSuccess = false;
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
                $lock->release();
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

    /** 锁文件路径。 */
    private function lockPath(): string
    {
        if ($this->lockPath !== null) {
            return $this->lockPath;
        }

        return \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'kode-scheduling-' . \md5($this->name) . '.lock';
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
}
