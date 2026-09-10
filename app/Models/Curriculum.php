<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A department may run several curricula at once.
 *
 * A college mid-transition teaches its incoming cohort from the new curriculum
 * while the upper years finish on the old one, so "active" is not exclusive.
 * Which curriculum applies to a given cohort is recorded on the section
 * (sections.curriculum_id), not inferred here.
 */
class Curriculum extends Model
{
    protected $table = 'curriculum';

    protected $fillable = ['name', 'department_id', 'program_id', 'code', 'effective_school_year', 'status', 'description'];

    /** The newest active curriculum of its department/program group. */
    public const LIFECYCLE_NEW = 'new';

    /** An active curriculum that a newer one has superseded — still teaching upper years. */
    public const LIFECYCLE_OLD = 'old';

    /** The only active curriculum in its group; an old/new badge would say nothing. */
    public const LIFECYCLE_ONLY = 'only';

    /**
     * Out of service — either not published yet or withdrawn.
     *
     * There is deliberately no separate "draft": a curriculum is in service or it
     * is not, and both cases offer the same next step, which is to activate it.
     */
    public const LIFECYCLE_DEACTIVATED = 'deactivated';

    public const LIFECYCLE_ARCHIVED = 'archived';

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function sections()
    {
        return $this->hasMany(Sections::class, 'curriculum_id');
    }

    /**
     * Cohorts that do not merely point at this curriculum but have a timetable
     * plotted from it.
     *
     * Assignment alone is undone with a dropdown and strands nothing, so it is
     * not a reason to refuse retirement. Generated schedule rows are: they were
     * built from this curriculum's course list and would outlive it.
     *
     * The nested clause is correlated to the section rather than to a literal
     * curriculum id so the relation also works under withCount(), where no
     * parent key is bound yet. Rows predating schedules.curriculum_id carry
     * null; fall back to the cohort's own assignment for those.
     */
    public function scheduledSections()
    {
        return $this->sections()->whereHas('schedules', function ($schedules): void {
            $schedules->where(function ($scope): void {
                $scope->whereColumn('schedules.curriculum_id', 'sections.curriculum_id')
                    ->orWhereNull('schedules.curriculum_id');
            });
        });
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class, 'curriculum_course', 'curriculum_id', 'course_id')
            ->withPivot(['year_level', 'semester'])
            ->withTimestamps();
    }

    // Alias for subjects to support legacy calls/tests
    public function subjects()
    {
        return $this->courses();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * The curricula a section may be pointed at: same department, and either
     * program-wide or scoped to the section's own program.
     */
    public function scopeSelectableFor($query, int $departmentId, ?int $programId)
    {
        return $query
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->where(function ($scope) use ($programId): void {
                $scope->whereNull('program_id');
                if ($programId !== null) {
                    $scope->orWhere('program_id', $programId);
                }
            });
    }

    /**
     * Tags each curriculum as new/old within its own department+program group so
     * the UI can label a transition without anybody maintaining a flag by hand.
     *
     * The ranking is by effective school year, newest first, with the id as the
     * tie-breaker. Only active curricula are ranked: a deactivated or archived
     * one is out of service, so neither can be "the new one".
     *
     * @param  EloquentCollection<int, self>|Collection<int, self>  $curricula
     * @return EloquentCollection<int, self>|Collection<int, self> the same instances, with `lifecycle` and `lifecycle_label` appended
     */
    public static function annotateLifecycle($curricula)
    {
        $activeRanks = [];

        foreach ($curricula->where('status', 'active')->groupBy(static fn (self $curriculum): string => self::groupKey($curriculum)) as $key => $group) {
            $ordered = $group
                ->sortByDesc(static fn (self $curriculum): string => sprintf('%s|%012d', (string) $curriculum->effective_school_year, (int) $curriculum->id))
                ->values();

            foreach ($ordered as $rank => $curriculum) {
                $activeRanks[(int) $curriculum->id] = [
                    'rank' => $rank,
                    'total' => $ordered->count(),
                    'key' => $key,
                ];
            }
        }

        foreach ($curricula as $curriculum) {
            $lifecycle = match ((string) $curriculum->status) {
                'archived' => self::LIFECYCLE_ARCHIVED,
                'deactivated' => self::LIFECYCLE_DEACTIVATED,
                default => self::activeLifecycle($activeRanks[(int) $curriculum->id] ?? null),
            };

            $curriculum->setAttribute('lifecycle', $lifecycle);
            $curriculum->setAttribute('lifecycle_label', self::lifecycleLabel($lifecycle));
        }

        return $curricula;
    }

    /** @param  array{rank: int, total: int, key: string}|null  $rank */
    private static function activeLifecycle(?array $rank): string
    {
        if ($rank === null || $rank['total'] <= 1) {
            return self::LIFECYCLE_ONLY;
        }

        return $rank['rank'] === 0 ? self::LIFECYCLE_NEW : self::LIFECYCLE_OLD;
    }

    public static function lifecycleLabel(string $lifecycle): string
    {
        return match ($lifecycle) {
            self::LIFECYCLE_NEW => 'New Curriculum',
            self::LIFECYCLE_OLD => 'Old Curriculum',
            self::LIFECYCLE_DEACTIVATED => 'Deactivated',
            self::LIFECYCLE_ARCHIVED => 'Archived',
            default => 'Active',
        };
    }

    private static function groupKey(self $curriculum): string
    {
        return ((int) $curriculum->department_id).':'.($curriculum->program_id === null ? 'all' : (int) $curriculum->program_id);
    }
}
