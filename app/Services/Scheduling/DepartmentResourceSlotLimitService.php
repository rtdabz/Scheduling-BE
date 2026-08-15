<?php

namespace App\Services\Scheduling;

use App\Models\Departments;

class DepartmentResourceSlotLimitService
{
    /** @var array<int, array{online: int, field: int}> */
    private array $cache = [];

    /** @return array{online: int, field: int} */
    public function forDepartment(int $departmentId): array
    {
        return $this->cache[$departmentId] ??= (function () use ($departmentId): array {
            $department = Departments::query()->find($departmentId);

            return [
                'online' => max(1, (int) ($department?->online_slot_limit ?? 3)),
                'field' => max(1, (int) ($department?->field_slot_limit ?? 3)),
            ];
        })();
    }

    public function online(int $departmentId): int
    {
        return $this->forDepartment($departmentId)['online'];
    }

    public function field(int $departmentId): int
    {
        return $this->forDepartment($departmentId)['field'];
    }
}
