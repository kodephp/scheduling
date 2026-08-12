<?php

declare(strict_types=1);

namespace Kode\Scheduling\Coordinator;

use Kode\Scheduling\Contract\CoordinatorInterface;

/**
 * 默认协调器：单机模式，恒为“应当派发”。
 *
 * 不引入任何外部依赖，所有 run() 都会正常触发到期任务。集群部署时
 * 可自行实现 CoordinatorInterface（如基于 Redis 的 Leader 选举）后替换。
 */
final class LocalCoordinator implements CoordinatorInterface
{
    #[\Override]
    public function tick(): void
    {
        // 无状态，空操作
    }

    #[\Override]
    public function shouldDispatch(): bool
    {
        return true;
    }
}
