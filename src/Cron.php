<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Exception\CronExpressionError;

/**
 * Cron 表达式解析与匹配。
 *
 * 支持两种精度：
 *   - 5 段（分钟精度，标准 Vixie cron）：  分 时 日 月 周
 *   - 6 段（秒级精度，Quartz 风格）：    秒 分 时 日 月 周
 *
 * 为什么支持“秒”而不支持“毫秒”？
 *   本库是墙钟（wall-clock）定时调度：由系统 crontab 每分钟触发，或常驻
 *   keepAlive 守护循环驱动。最小有意义的调度粒度就是“秒”——再细到毫秒需要
 *   密集轮询、徒增 CPU 开销，而 PHP 又非实时系统，毫秒级触发本就不精确。
 *   因此本库最高支持到秒，主动放弃毫秒。
 *
 * 每段可使用的语法：
 *   *          任意值
 *   a-b        范围（含端点）
 *   a,b,c      列表
 *   a/n        步进（从 a 开始每 n 个）
 *   a-b/n      范围内步进
 *   star/n     从起点开始每 n 个（即 "星号 除号 n"）
 * 月与周支持英文缩写（JAN..DEC、SUN..SAT，大小写不限）。
 * 另支持 @yearly/@annually/@monthly/@weekly/@daily/@hourly 宏（均为分钟精度）。
 *
 * 关于「日」与「周」的特殊规则（遵循 Vixie cron）：
 *   当日字段与周字段同时为限定值时，二者为“或”关系——
 *   即某天只要满足“日匹配”或“周匹配”其一即视为命中。
 */
final class Cron
{
    /** 字段键名（6 段时含 second，5 段时从 minute 起）。 */
    private const FIELDS_6 = ['second', 'minute', 'hour', 'day', 'month', 'weekday'];
    private const FIELDS_5 = ['minute', 'hour', 'day', 'month', 'weekday'];

    /** 每个字段的合法取值区间 [min, max]。 */
    private const array RANGES = [
        'second'  => [0, 59],
        'minute'  => [0, 59],
        'hour'    => [0, 23],
        'day'     => [1, 31],
        'month'   => [1, 12],
        'weekday' => [0, 7], // 0 与 7 均表示周日
    ];

    /** 月份英文缩写（大写）映射。 */
    private const array MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** 星期英文缩写（大写）映射。 */
    private const array WEEKDAY_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /** 常用宏，展开为完整 5 段表达式。 */
    private const array MACROS = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    /** 每个字段解析后的“允许取值集合”（已去重、已排序）。 */
    private array $allowed;

    /** 规范化后的原始表达式片段（宏展开后），用于可读描述。 */
    private array $normalized = [];

    /** 是否包含秒字段（6 段）。 */
    private bool $hasSeconds = false;

    /** 原始表达式字符串（用于报错与展示）。 */
    private string $raw;

    /**
     * @param string $expression 5 段或 6 段表达式，或 @宏
     * @throws CronExpressionError 表达式非法时
     */
    public function __construct(string $expression)
    {
        $this->raw = $expression;
        $expr = trim($expression);

        // 宏展开
        if (isset(self::MACROS[strtolower($expr)])) {
            $expr = self::MACROS[strtolower($expr)];
        }

        $segments = preg_split('/\s+/', $expr);
        if ($segments === false || (count($segments) !== 5 && count($segments) !== 6)) {
            throw CronExpressionError::for($expression, '必须为 5 段（分 时 日 月 周）或 6 段（秒 分 时 日 月 周），或用 @宏');
        }

        $this->hasSeconds = count($segments) === 6;
        $fields = $this->hasSeconds ? self::FIELDS_6 : self::FIELDS_5;

        $this->allowed = [];
        foreach ($fields as $i => $name) {
            $this->allowed[$name] = $this->parseField($segments[$i], $name, $expression);
        }

        // 周字段中若出现了 7，则等价于 0（周日），一并纳入
        if (in_array(7, $this->allowed['weekday'], true)) {
            $this->allowed['weekday'][] = 0;
            $this->allowed['weekday'] = array_values(array_unique($this->allowed['weekday']));
        }

        $this->normalized = $segments;
    }

    /**
     * 判断给定时刻是否命中本表达式。
     *
     * @param \DateTimeInterface $now 待检测时刻（建议使用 DateTimeImmutable）
     */
    public function isDue(\DateTimeInterface $now): bool
    {
        $second  = (int) $now->format('s');
        $minute  = (int) $now->format('i');
        $hour    = (int) $now->format('G');
        $day     = (int) $now->format('j');
        $month   = (int) $now->format('n');
        $weekday = (int) $now->format('w'); // 0=周日 .. 6=周六

        // 秒（仅 6 段表达式需要判定；5 段时秒恒匹配）
        if ($this->hasSeconds && !$this->inField('second', $second)) {
            return false;
        }

        // 分、时、月必须全部命中（与关系）
        if (!$this->inField('minute', $minute)) {
            return false;
        }
        if (!$this->inField('hour', $hour)) {
            return false;
        }
        if (!$this->inField('month', $month)) {
            return false;
        }

        // 日与周遵循“或”规则
        $dayAll  = $this->isAll('day', 1, 31);
        $weekAll = $this->isAll('weekday', 0, 7);

        if ($dayAll && $weekAll) {
            return true; // 两者均为 *，任意日命中
        }
        if (!$dayAll && !$weekAll) {
            return $this->inField('day', $day) || $this->inField('weekday', $weekday);
        }
        if (!$dayAll) {
            return $this->inField('day', $day);
        }
        return $this->inField('weekday', $weekday);
    }

    /**
     * 计算从 $from 起的下一个命中时刻。
     *
     * 采用“逐分钟向前搜索”的稳妥策略，最多向前 5 年，
     * 足以覆盖闰年与 2 月 29 日等极端情形。
     *
     * @throws CronExpressionError 5 年内无匹配时
     */
    public function nextRun(\DateTimeInterface $from): \DateTimeImmutable
    {
        if ($this->hasSeconds) {
            // 秒级：从“下一秒（微秒归零）”开始逐秒搜索
            $next = \DateTimeImmutable::createFromInterface($from)->modify('+1 second');
            $cursor = $next->setTime((int) $next->format('H'), (int) $next->format('i'), (int) $next->format('s'), 0);
            $step = '+1 second';
        } else {
            // 分钟级：从“下一分钟（秒归零）”开始逐分钟搜索
            $cursor = \DateTimeImmutable::createFromInterface($from)->modify('+1 minute');
            $cursor = $cursor->setTime((int) $cursor->format('H'), (int) $cursor->format('i'), 0);
            $step = '+1 minute';
        }

        $limit = $cursor->modify('+5 years');

        while ($cursor <= $limit) {
            if ($this->isDue($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->modify($step);
        }

        throw CronExpressionError::for($this->raw, '未来 5 年内未找到匹配时刻');
    }

    /** 返回原始表达式。 */
    public function expression(): string
    {
        return $this->raw;
    }

    /** 是否包含秒字段（6 段表达式）。 */
    public function hasSeconds(): bool
    {
        return $this->hasSeconds;
    }

    /**
     * 输出人类可读的中文描述（尽力而为；非常规模式会回退为原始表达式）。
     */
    public function describe(): string
    {
        $offset = $this->hasSeconds ? 1 : 0;
        $second = $this->hasSeconds ? $this->normalized[0] : '*';
        $minute = $this->normalized[$offset];
        $hour   = $this->normalized[$offset + 1];
        $day    = $this->normalized[$offset + 2];
        $month  = $this->normalized[$offset + 3];
        $weekday = $this->normalized[$offset + 4];

        $parts = [];
        $sec = $this->describeSecond($second);
        if ($sec !== '') {
            $parts[] = $sec;
        }
        $wd = $this->describeWeekday($weekday);
        if ($wd !== '') {
            $parts[] = $wd;
        }
        $mo = $this->describeMonth($month);
        if ($mo !== '') {
            $parts[] = $mo;
        }
        $dy = $this->describeDay($day);
        if ($dy !== '') {
            $parts[] = $dy;
        }
        $parts[] = $this->describeTime($minute, $hour);

        return \implode('，', $parts);
    }

    /** 秒字段的中文描述（仅 6 段表达式有意义）。 */
    private function describeSecond(string $second): string
    {
        if ($second === '*') {
            return '';
        }
        if (\preg_match('#^\*/(\d+)$#', $second, $m)) {
            return '每 ' . $m[1] . ' 秒';
        }
        if (\preg_match('#^\d+$#', $second)) {
            return '第 ' . $second . ' 秒';
        }

        return '秒[' . $second . ']';
    }

    /** 时间字段的中文描述。 */
    private function describeTime(string $minute, string $hour): string
    {
        $allMin = $this->isAll('minute', 0, 59);
        $allHour = $this->isAll('hour', 0, 23);

        if ($allMin && $allHour) {
            return $this->hasSeconds ? '每秒' : '每分钟';
        }
        // 每 n 分钟：分字段为 */n，时字段为 *
        if ($allHour && \preg_match('#^\*/(\d+)$#', $minute, $m)) {
            return '每 ' . $m[1] . ' 分钟';
        }
        // 整点：分=0，时=*
        if ($minute === '0' && $allHour) {
            return '每小时整点';
        }
        // 单时刻：分、时均为单值
        if (!\str_contains($minute, ',') && !\str_contains($hour, ',')
            && \preg_match('#^\d+$#', $minute) && \preg_match('#^\d+$#', $hour)) {
            return \sprintf('%02d:%02d', (int) $hour, (int) $minute);
        }
        // 每小时第 m 分
        if ($allHour && \preg_match('#^\d+$#', $minute)) {
            return '每小时第 ' . $minute . ' 分';
        }
        // 某点内的每一分钟
        if ($allMin && \preg_match('#^\d+$#', $hour)) {
            return $hour . ' 点内的每一分钟';
        }

        return '分[' . $minute . '] 时[' . $hour . ']';
    }

    /** 星期字段的中文描述。 */
    private function describeWeekday(string $weekday): string
    {
        if ($weekday === '*') {
            return '';
        }
        if ($weekday === '1-5') {
            return '仅工作日';
        }
        if ($weekday === '0,6') {
            return '仅周末';
        }
        $names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        if (\preg_match('#^\d$#', $weekday)) {
            return '仅' . ($names[(int) $weekday] ?? $weekday);
        }
        // 列表或范围
        $items = \explode(',', $weekday);
        $mapped = [];
        foreach ($items as $it) {
            if (\preg_match('#^(\d+)-(\d+)$#', $it, $r)) {
                $mapped[] = ($names[(int) $r[1]] ?? $r[1]) . '~' . ($names[(int) $r[2]] ?? $r[2]);
            } elseif (\preg_match('#^\d$#', $it)) {
                $mapped[] = $names[(int) $it] ?? $it;
            } else {
                $mapped[] = $it;
            }
        }

        return '每' . \implode('、', $mapped);
    }

    /** 月份字段的中文描述。 */
    private function describeMonth(string $month): string
    {
        if ($month === '*') {
            return '';
        }
        if ($month === '1,4,7,10') {
            return '每季度首月';
        }
        if (\preg_match('#^\d+$#', $month)) {
            return $month . ' 月';
        }

        return '月份[' . $month . ']';
    }

    /** 日字段的中文描述。 */
    private function describeDay(string $day): string
    {
        if ($day === '*') {
            return '';
        }
        if ($day === '1') {
            return '每月 1 号';
        }
        if (\preg_match('#^\d+$#', $day)) {
            return '每月 ' . $day . ' 号';
        }

        return '日期[' . $day . ']';
    }

    /**
     * 解析单个字段为“允许取值集合”。
     *
     * @return list<int>
     * @throws CronExpressionError
     */
    private function parseField(string $field, string $name, string $root): array
    {
        $range = self::RANGES[$name];
        $names = $name === 'month' ? self::MONTH_NAMES : ($name === 'weekday' ? self::WEEKDAY_NAMES : []);
        $values = [];

        if ($field === '') {
            throw CronExpressionError::for($root, \sprintf('字段 "%s" 不能为空', $name));
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                throw CronExpressionError::for($root, \sprintf('字段 "%s" 存在空项', $name));
            }

            // 解析步进 /n
            $step = 1;
            $hasStep = false;
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                $stepStr = trim($stepStr);
                if (!ctype_digit($stepStr) || (int) $stepStr < 1) {
                    throw CronExpressionError::for($root, \sprintf('字段 "%s" 步进值非法："%s"', $name, $stepStr));
                }
                $step = (int) $stepStr;
                $hasStep = true;
            }

            // 区间 a-b 或单值
            if ($part === '*') {
                $start = $range[0];
                $end = $range[1];
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $start = $this->resolveToken(trim($a), $names, $name, $root);
                $end = $this->resolveToken(trim($b), $names, $name, $root);
            } else {
                $v = $this->resolveToken($part, $names, $name, $root);
                $start = $v;
                // "10/5" 按 Vixie 语义是「从 10 起每 5 个直到字段上限」，
                // 而不是只有 10 一个值——end 必须扩到区间末端。
                $end = $hasStep ? $range[1] : $v;
            }

            // 边界校验
            if ($start < $range[0] || $end > $range[1] || $start > $end) {
                throw CronExpressionError::for(
                    $root,
                    \sprintf('字段 "%s" 取值 %d-%d 超出合法区间 [%d, %d]', $name, $start, $end, $range[0], $range[1])
                );
            }

            for ($v = $start; $v <= $end; $v += $step) {
                $values[] = $v;
            }
        }

        sort($values);

        return array_values(array_unique($values));
    }

    /**
     * 将单个 token 解析为整数：支持名称（JAN/SUN 等）或纯数字。
     *
     * @param array<string,int> $names
     * @throws CronExpressionError
     */
    private function resolveToken(string $token, array $names, string $name, string $root): int
    {
        if (isset($names[strtoupper($token)])) {
            return $names[strtoupper($token)];
        }
        if (ctype_digit($token)) {
            return (int) $token;
        }
        throw CronExpressionError::for($root, \sprintf('字段 "%s" 含非法项："%s"', $name, $token));
    }

    /** 判断某字段的允许集合是否覆盖其全部区间（即等价于 *）。 */
    private function isAll(string $name, int $min, int $max): bool
    {
        $allowed = $this->allowed[$name];
        if (count($allowed) !== ($max - $min + 1)) {
            return false;
        }
        foreach ($allowed as $v) {
            if ($v < $min || $v > $max) {
                return false;
            }
        }
        return true;
    }

    /** 判断某值是否在该字段的允许集合中。 */
    private function inField(string $name, int $value): bool
    {
        return in_array($value, $this->allowed[$name], true);
    }
}
