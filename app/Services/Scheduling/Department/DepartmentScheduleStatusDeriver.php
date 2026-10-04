<?php

namespace App\Services\Scheduling\Department;

class DepartmentScheduleStatusDeriver
{
    private const RANK = [
        'draft' => 0,
        'revision' => 0,
        'completed' => 1,
        'submitted' => 2,
        'approved_by_dean' => 3,
        'conditionally_approved' => 3,
        'approved' => 4,
    ];

    /**
     * @param  list<string>  $statuses  Raw `schedules.status` values, or already
     *                                  derived section statuses.
     */
    public function derive(array $statuses): string
    {
        if (in_array('finalized', $statuses, true)) {
            return 'approved';
        }

        if ($statuses === []) {
            return 'draft';
        }

        $minRank = PHP_INT_MAX;
        $result = 'draft';

        foreach ($statuses as $raw) {
            $normalised = $this->normalise($raw);
            $rank = self::RANK[$normalised] ?? 0;

            if ($rank < $minRank) {
                $minRank = $rank;
                $result = $normalised;
            }
        }

        return $result;
    }

    private function normalise(string $status): string
    {
        return match (true) {
            in_array($status, ['faculty_assignment', 'reassignment', 'finalized'], true) => 'approved',
            $status === 'conditionally_approved' => 'conditionally_approved',
            $status === 'approved_by_dean' => 'approved_by_dean',
            $status === 'approved' => 'approved',
            $status === 'submitted' => 'submitted',
            $status === 'completed' => 'completed',
            $status === 'revision' => 'revision',
            default => 'draft',
        };
    }
}
