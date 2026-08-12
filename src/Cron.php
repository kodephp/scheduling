<?php

declare(strict_types=1);

namespace Kode\Scheduling;

use Kode\Scheduling\Exception\CronExpressionError;

/**
 * Cron 表达式解析与匹配。
 *
 * 支持标准 5 段式表达式：
 *   分 时 日 月 周
 * 每段可使用的语法：
 *   *          任意值
 *   a-b        范围（含端点）
 *   a,b,c      列表
 *   a/n        步进（从 a 开始每 n 个）
 *   a-b/n      范围内步进
 *   star/n     从起点开始每 n 个（即 "星号 除号 n"）
 * 月与周支持英文缩写（JAN..DEC、SUN..SAT，大小写不限）。
 * 另支持 @yearly/@annually/@monthly/@weekly/@daily/@hourly 宏。
 *
 * 关于「日」与「周」的特殊规则（遵循 Vixie cron）：
 *   当日字段与周字段同时为限定值时，二者为“或”关系——
 *   即某天只要满足“日匹配”或“周匹配”其一即视为命中。
 */
final class Cron
{
    /** 5 个字段的键名，顺序固定。 */
    private const FIELDS = ['minute', 'hour', 'day', 'month', 'weekday'];

    /** 每个字段的合法取值区间 [min, max]。 */
    private const RANGES = [
        'minute' => [0, 59],
        'hour'   => [0, 23],
        'day'    => [1, 31],
        'month'  => [1, 12],
        'weekday' => [0, 7], // 0 与 7 均表示周日
    ];

    /** 月份英文缩写（大写）映射。 */
    private const MONTH_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    /** 星期英文缩写（大写）映射。 */
    private const WEEKDAY_NAMES = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /** 常用宏，展开为完整 5 段表达式。 */
    private const MACROS = [
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    ];

    /** 每个字段解析后的“允许取值集合”（已去重、已排序）。 */
    private array $allowed;

    /** 原始表达式字符串（用于报错与展示）。 */
    private string $raw;

    /**
     * @param string $expression 5 段表达式或 @宏
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
        if ($segments === false || count($segments) !== 5) {
            throw CronExpressionError::for($expression, '必须为 5 段（分 时 日 月 周），或用 @宏');
        }

        $this->allowed = [];
        foreach (self::FIELDS as $i => $name) {
            $this->allowed[$name] = $this->parseField($segments[$i], $name, $expression);
        }

        // 周字段中若出现了 7，则等价于 0（周日），一并纳入
        if (in_array(7, $this->allowed['weekday'], true)) {
            $this->allowed['weekday'][] = 0;
            $this->allowed['weekday'] = array_values(array_unique($this->allowed['weekday']));
        }
    }

    /**
     * 判断给定时刻是否命中本表达式。
     *
     * @param \DateTimeInterface $now 待检测时刻（建议使用 DateTimeImmutable）
     */
    public function isDue(\DateTimeInterface $now): bool
    {
        $minute  = (int) $now->format('i');
        $hour    = (int) $now->format('G');
        $day     = (int) $now->format('j');
        $month   = (int) $now->format('n');
        $weekday = (int) $now->format('w'); // 0=周日 .. 6=周六

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
        $cursor = \DateTimeImmutable::createFromInterface($from)
            ->modify('+1 minute')
            ->setTime((int) $from->format('H'), (int) $from->format('i'), 0);

        $limit = $cursor->modify('+5 years');

        while ($cursor <= $limit) {
            if ($this->isDue($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->modify('+1 minute');
        }

        throw CronExpressionError::for($this->raw, '未来 5 年内未找到匹配时刻');
    }

    /** 返回原始表达式。 */
    public function expression(): string
    {
        return $this->raw;
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
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                $stepStr = trim($stepStr);
                if (!ctype_digit($stepStr) || (int) $stepStr < 1) {
                    throw CronExpressionError::for($root, \sprintf('字段 "%s" 步进值非法："%s"', $name, $stepStr));
                }
                $step = (int) $stepStr;
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
                $end = $v;
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
