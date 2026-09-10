<?php

namespace App\Services\Scheduling;

use App\Models\Departments;

/**
 * Concurrency limits for the two resources that are not real rooms.
 *
 * A NULL limit means unlimited, and that is the default. Neither resource is a
 * room: an online class occupies no space at all, and the field is open ground
 * that several sections can share. Capping them at a small number modelled
 * scarcity that does not exist and became the binding constraint on whole year
 * levels -- a three-hour NSTP course pinned to Saturday against a field limit of
 * three could be placed nine times for a year level that needed twenty-one.
 *
 * A department that genuinely needs a ceiling can still set one; it is then
 * enforced exactly as before, and the year-level pre-check reports the number
 * required when it binds.
 */
class DepartmentResourceSlotLimitService
{
    /**
     * Stand-in for "no ceiling". Large enough that no concurrency check can
     * trip, small enough to survive a JSON round-trip intact -- the snapshot
     * carries these limits and is serialised, and PHP_INT_MAX would come back
     * as a float.
     */
    public const UNLIMITED = 1000000;

    /** @var array<int, array{online: int, field: int}> */
    private array $cache = [];

    /** @return array{online: int, field: int} */
    public function forDepartment(int $departmentId): array
    {
        return $this->cache[$departmentId] ??= (function () use ($departmentId): array {
            $department = Departments::query()->find($departmentId);

            return [
                'online' => self::resolve($department?->online_slot_limit),
                'field' => self::resolve($department?->field_slot_limit),
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

    /**
     * A stored limit of NULL, or of zero or less, means the resource is not
     * capped. Anything positive is honoured as written.
     */
    public static function resolve(mixed $limit): int
    {
        if ($limit === null || $limit === '') {
            return self::UNLIMITED;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : self::UNLIMITED;
    }
}
