<?php

namespace App\Services\Scheduling\Department;

/**
 * Collapses many schedule statuses into the one status a section, cohort or
 * department is shown as.
 *
 * Extracted from DepartmentScheduleController so the All Schedules overview
 * reports the same stage the submission workflow acts on. Two copies of this
 * rule would let a department card read "approved" while Submit still treats
 * it as drafting.
 */
class DepartmentScheduleStatusDeriver
{
    /** Canonical stages, ordered by how far through approval they are. */
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
     * The least advanced status wins: a cohort is not submitted until all of it
     * is.
     *
     * @param  list<string>  $statuses  Raw `schedules.status` values, or already
     *                                  derived section statuses.
     */
    public function derive(array $statuses): string
    {
        // A finalized meeting belongs to a completed approval cohort. Legacy
        // duplicate draft rows must not pull that section back into drafting or
        // cause it to be included in a later revision submission.
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

    /** Folds the extended `schedules.status` enum onto the canonical stages. */
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
            // draft, rejected, rejected_by_dean
            default => 'draft',
        };
    }
}
