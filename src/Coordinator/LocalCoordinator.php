<?php

declare(strict_types=1);

namespace Kode\Scheduling\Coordinator;

use Kode\Scheduling\Contract\CoordinatorInterface;

/**
 * 默认协调器：单机模式，恒为“应当派发”。
 *
 * 不引入任何分布式依赖，所有 run() 都会正常触发到期任务。集群部署时
 * 请改用 LeaderCoordinator。
 */
final class LocalCoordinator implements CoordinatorInterface
{
    public function tick(): void
    {
        // 无状态，空操作
    }

    public function shouldDispatch(): bool
    {
        return true;
    }
}
