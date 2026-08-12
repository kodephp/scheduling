<?php

declare(strict_types=1);

namespace Kode\Scheduling\Coordinator;

use Kode\Process\Cluster;
use Kode\Scheduling\Contract\CoordinatorInterface;
use Kode\Scheduling\Exception\SchedulingError;

/**
 * 分布式协调器：基于 kode/process 的 Leader 选举。
 *
 * 集群中多个节点运行同一份调度配置，但只有“Leader”节点会真正派发任务，
 * 其余节点本次 run() 直接空转，避免重复劳动。Leader 崩溃后，租约（ttl）内
 * 其他节点会自动竞选上位，实现高可用。
 *
 * 与 ProcessMutex 叠加：即便 Leader 切换的瞬间出现两个节点“同认为自己是
 * Leader”，单任务的分布式锁仍会保证该任务只被一个节点抢到执行权。
 *
 * 依赖：composer require kode/process（未安装时实例化会给出明确提示）。
 * 可选：Cluster::make('redis', [...]) 指定共享存储后端后再使用本协调器。
 */
final class LeaderCoordinator implements CoordinatorInterface
{
    /**
     * @param string      $name   选举名（同名节点竞争同一把交椅）
     * @param string|null $nodeId 本节点标识；null=自动取 主机名-PID
     * @param float       $ttl    租约时长（秒）；Leader 失联多久后发生切换
     */
    public function __construct(
        private readonly string $name = 'kode-scheduling',
        private readonly ?string $nodeId = null,
        private readonly float $ttl = 15.0,
    ) {
        if (!\class_exists(Cluster::class)) {
            throw SchedulingError::for('LeaderCoordinator', '未安装 kode/process，请先执行：composer require kode/process');
        }
    }

    public function tick(): void
    {
        // 刷新 Leader 租约；返回 true 表示本次刚刚当选
        Cluster::election($this->name, $this->nodeId, $this->ttl)->tick();
    }

    public function shouldDispatch(): bool
    {
        return Cluster::election($this->name, $this->nodeId, $this->ttl)->isLeader();
    }
}
