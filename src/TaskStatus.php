<?php

declare(strict_types=1);

namespace Kode\Scheduling;

/**
 * 单个任务的执行终态（由 Runner 产出，供 Scheduler 汇总进 RunReport）。
 *
 * 用枚举替代裸字符串，配合 PHP 8.3 的强类型与 match 表达式，
 * 既能获得编译期可校验的取值集合，也避免拼写错误。
 */
enum TaskStatus: string
{
    /** 成功执行。 */
    case Success = 'success';

    /** 被跳过（条件不满足 / 锁冲突 / 时间窗口外）。 */
    case Skipped = 'skipped';

    /** 执行失败（回调抛出异常）。 */
    case Error = 'error';
}
