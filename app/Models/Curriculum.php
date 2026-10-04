<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Curriculum extends Model
{
    protected $table = 'curriculum';

    protected $fillable = ['name', 'department_id', 'program_id', 'code', 'effective_school_year', 'status', 'description'];

    protected static function booted(): void
    {
        static::updated(function (self $curriculum): void {
            if ($curriculum->wasChanged(['status', 'effective_school_year'])) {
                Course::syncPlacementFromCurricula($curriculum->courses()->pluck('courses.id'));
            }
        });
    }

    public const LIFECYCLE_NEW = 'new';

    public const LIFECYCLE_OLD = 'old';

    public const LIFECYCLE_ONLY = 'only';

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

    public function subjects()
    {
        return $this->courses();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

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
