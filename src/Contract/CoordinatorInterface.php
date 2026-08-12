<?php

declare(strict_types=1);

namespace Kode\Scheduling\Contract;

/**
 * 调度协调器契约（用于多节点分布式部署时的“谁来决定派发”）。
 *
 * 在单机部署下恒为 true；在集群部署下，由 Leader 选举保证“同一时刻
 * 只有一个节点真正触发任务派发”，其余节点空转，避免重复劳动。
 * 再配合 MutexInterface 的分布式锁，即使 Leader 切换瞬间出现竞态，
 * 也能保证同一任务不会被两个节点同时执行。
 *
 *  - LocalCoordinator  ：默认，恒派发（单机）；
 *  - LeaderCoordinator ：基于 kode/process 的 Leader 选举，仅 Leader 派发。
 */
interface CoordinatorInterface
{
    /**
     * 每次运行前心跳（如刷新 Leader 租约）。无状态实现可为空操作。
     */
    public function tick(): void;

    /**
     * 本节点此刻是否应当触发调度派发。
     *
     * @return bool true=应当派发；false=本节点本次不派发（如非 Leader）
     */
    public function shouldDispatch(): bool;
}
