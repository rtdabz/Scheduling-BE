<?php

namespace App\Services\Curriculum;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Schedule;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hours per unit for one course as placed in one curriculum. The course keeps its academic units; the
 * placement stores how many minutes each lecture and laboratory unit takes and the weekly minutes that
 * result. Every scheduling path (generator, manual plotting, recommendations) reads those minutes
 * through SchedulingPolicy::placedCourse().
 */
final class CurriculumCourseHours
{
    /** Hand-entered placement with no conversion recorded: 1 unit = 1 hour for both components. */
    public const SOURCE_LEGACY = 'legacy';

    /** Rates copied from the department settings when the curriculum was imported. */
    public const SOURCE_IMPORT = 'import';

    /** Rates copied from the department defaults through the hours editor. */
    public const SOURCE_DEPARTMENT = 'department';

    /** Rates set for this course in this curriculum. */
    public const SOURCE_CUSTOM = 'custom';

    public const SNAPSHOT_VERSION = 1;

    public const LEGACY_MINUTES_PER_UNIT = 60;

    /**
     * The hours a placement uses now.
     *
     * @return array{lecture_units: int, lab_units: int, lecture_minutes_per_unit: int, laboratory_minutes_per_unit: int, lecture_minutes: int, laboratory_minutes: int, total_minutes: int, source: string}
     */
    public static function describe(Course $course, ?object $pivot): array
    {
        $lectureUnits = max(0, (int) $course->lecture_hours);
        $labUnits = max(0, (int) $course->lab_hours);
        $snapshot = self::snapshot($pivot);

        if ($snapshot === null) {
            $lectureRate = self::LEGACY_MINUTES_PER_UNIT;
            $labRate = self::LEGACY_MINUTES_PER_UNIT;
            $source = self::SOURCE_LEGACY;
        } else {
            $lectureRate = (int) ($snapshot['lecture_minutes_per_unit'] ?? self::LEGACY_MINUTES_PER_UNIT);
            $labRate = (int) ($snapshot['laboratory_minutes_per_unit'] ?? self::LEGACY_MINUTES_PER_UNIT);
            $source = in_array($snapshot['source'] ?? null, [self::SOURCE_DEPARTMENT, self::SOURCE_CUSTOM], true)
                ? (string) $snapshot['source']
                : self::SOURCE_IMPORT;
        }

        // Stored minutes are what the scheduler reads; fall back to the conversion only when none were stored.
        $lectureMinutes = $pivot?->lecture_minutes !== null && $snapshot !== null ? (int) $pivot->lecture_minutes : $lectureUnits * $lectureRate;
        $labMinutes = $pivot?->laboratory_minutes !== null && $snapshot !== null ? (int) $pivot->laboratory_minutes : $labUnits * $labRate;

        return [
            'lecture_units' => $lectureUnits,
            'lab_units' => $labUnits,
            'lecture_minutes_per_unit' => $lectureRate,
            'laboratory_minutes_per_unit' => $labRate,
            'lecture_minutes' => $lectureMinutes,
            'laboratory_minutes' => $labMinutes,
            'total_minutes' => $lectureMinutes + $labMinutes,
            'source' => $source,
        ];
    }

    /**
     * Sets the placement's hours per unit and recalculates its weekly minutes. Units are never changed.
     *
     * @param  array{use_department_default?: bool, lecture_minutes_per_unit?: int, laboratory_minutes_per_unit?: int, confirm_draft_changes?: bool}  $input
     * @return array<string, mixed> the placement's new hours
     *
     * @throws CurriculumCourseHoursBlocked
     */
    public function update(Curriculum $curriculum, Course $course, array $input, ?int $userId): array
    {
        return DB::transaction(function () use ($curriculum, $course, $input, $userId): array {
            Curriculum::query()->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            $pivot = DB::table('curriculum_course')
                ->where('curriculum_id', $curriculum->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();
            if ($pivot === null) {
                throw new CurriculumCourseHoursBlocked(sprintf('%s is not part of this curriculum.', $course->course_code), 404);
            }

            $useDefault = (bool) ($input['use_department_default'] ?? false);
            if ($useDefault) {
                $department = Departments::query()->findOrFail($curriculum->department_id);
                $lectureRate = (int) $department->lecture_minutes_per_unit;
                $labRate = (int) $department->laboratory_minutes_per_unit;
            } else {
                $lectureRate = (int) $input['lecture_minutes_per_unit'];
                $labRate = (int) $input['laboratory_minutes_per_unit'];
            }

            $before = self::describe($course, $pivot);
            $lectureMinutes = $before['lecture_units'] * $lectureRate;
            $labMinutes = $before['lab_units'] * $labRate;
            self::assertFitsSchedulingDay($course, $lectureMinutes, $labMinutes);

            $durationChanges = $lectureMinutes !== $before['lecture_minutes'] || $labMinutes !== $before['laboratory_minutes'];
            if ($durationChanges) {
                $this->guardExistingSchedules($curriculum, $course, (bool) ($input['confirm_draft_changes'] ?? false));
            }

            DB::table('curriculum_course')
                ->where('curriculum_id', $curriculum->id)
                ->where('course_id', $course->id)
                ->update([
                    'lecture_units' => $before['lecture_units'],
                    'lab_units' => $before['lab_units'],
                    'lecture_minutes' => $lectureMinutes,
                    'laboratory_minutes' => $labMinutes,
                    'conversion_snapshot' => json_encode([
                        'version' => self::SNAPSHOT_VERSION,
                        'basis' => 'weekly',
                        'source' => $useDefault ? self::SOURCE_DEPARTMENT : self::SOURCE_CUSTOM,
                        'lecture_minutes_per_unit' => $lectureRate,
                        'laboratory_minutes_per_unit' => $labRate,
                        'offering_department_id' => (int) $curriculum->department_id,
                        'updated_by' => $userId,
                        'updated_at' => now()->toIso8601String(),
                    ]),
                    'updated_at' => now(),
                ]);
            SchedulingPolicy::clearFieldCourseCache();

            $after = DB::table('curriculum_course')
                ->where('curriculum_id', $curriculum->id)
                ->where('course_id', $course->id)
                ->first();

            return self::describe($course, $after);
        });
    }

    /**
     * After a course's units change, every placement that records a conversion keeps its hours per unit
     * and gets its weekly minutes recalculated. Legacy placements already follow the course's units.
     */
    public static function recalculateForCourse(Course $course): void
    {
        $lectureUnits = max(0, (int) $course->lecture_hours);
        $labUnits = max(0, (int) $course->lab_hours);

        DB::table('curriculum_course')
            ->where('course_id', $course->id)
            ->whereNotNull('conversion_snapshot')
            ->get(['curriculum_id', 'conversion_snapshot'])
            ->each(function (object $row) use ($course, $lectureUnits, $labUnits): void {
                $snapshot = self::snapshot($row) ?? [];
                DB::table('curriculum_course')
                    ->where('curriculum_id', $row->curriculum_id)
                    ->where('course_id', $course->id)
                    ->update([
                        'lecture_units' => $lectureUnits,
                        'lab_units' => $labUnits,
                        'lecture_minutes' => $lectureUnits * (int) ($snapshot['lecture_minutes_per_unit'] ?? self::LEGACY_MINUTES_PER_UNIT),
                        'laboratory_minutes' => $labUnits * (int) ($snapshot['laboratory_minutes_per_unit'] ?? self::LEGACY_MINUTES_PER_UNIT),
                        'updated_at' => now(),
                    ]);
            });
        SchedulingPolicy::clearFieldCourseCache();
    }

    /** @return array<string, mixed>|null */
    private static function snapshot(?object $pivot): ?array
    {
        $raw = $pivot?->conversion_snapshot ?? null;
        if ($raw === null) {
            return null;
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function assertFitsSchedulingDay(Course $course, int $lectureMinutes, int $labMinutes): void
    {
        $maxMinutes = SchedulingPolicy::maxHoursPerComponent() * 60;
        foreach (['lecture' => $lectureMinutes, 'laboratory' => $labMinutes] as $component => $minutes) {
            if ($minutes > $maxMinutes) {
                throw new CurriculumCourseHoursBlocked(sprintf(
                    '%s would need %s of %s a week, more than the %s one teaching day can hold. Lower the hours per unit.',
                    $course->course_code,
                    self::hoursLabel($minutes),
                    $component,
                    self::hoursLabel($maxMinutes),
                ), 422);
            }
        }
    }

    /**
     * Classes already plotted for this course in this curriculum's active sections were sized to the old
     * hours. Submitted or approved classes block the change; drafts need an explicit confirmation.
     */
    private function guardExistingSchedules(Curriculum $curriculum, Course $course, bool $confirmDrafts): void
    {
        $schedules = $this->existingSchedules($curriculum, $course);
        if ($schedules->isEmpty()) {
            return;
        }

        $locked = $schedules->reject(fn (object $row): bool => in_array($row->status, Schedule::UNLOCKED_STATUSES, true));
        if ($locked->isNotEmpty()) {
            $sections = $locked->pluck('section_name')->unique()->values();
            throw new CurriculumCourseHoursBlocked(sprintf(
                'Cannot change the hours of %s: it has submitted or approved classes in %s. Recall or return those schedules to revision first.',
                $course->course_code,
                self::sectionList($sections),
            ), 422, ['blocking_sections' => $sections->all()]);
        }

        if (! $confirmDrafts) {
            $sections = $schedules->pluck('section_name')->unique()->values();
            throw new CurriculumCourseHoursBlocked(sprintf(
                '%s already has draft classes in %s sized to the current hours. They keep their times until you regenerate or re-plot them to match the new hours.',
                $course->course_code,
                self::sectionList($sections),
            ), 409, ['requires_confirmation' => true, 'affected_sections' => $sections->all()]);
        }
    }

    /** @return Collection<int, object{status: string, section_name: string}> */
    private function existingSchedules(Curriculum $curriculum, Course $course): Collection
    {
        return DB::table('schedules')
            ->join('sections', 'sections.id', '=', 'schedules.section_id')
            ->where('schedules.course_id', $course->id)
            ->whereNull('schedules.deleted_at')
            ->where('sections.curriculum_id', $curriculum->id)
            ->where('sections.status', 'active')
            ->where(fn ($scope) => $scope->whereColumn('schedules.curriculum_id', 'sections.curriculum_id')->orWhereNull('schedules.curriculum_id'))
            ->orderBy('sections.section_name')
            ->get(['schedules.status', 'sections.section_name']);
    }

    /** @param  Collection<int, string>  $sections */
    private static function sectionList(Collection $sections): string
    {
        $overflow = $sections->count() > 5 ? sprintf(' and %d more', $sections->count() - 5) : '';

        return $sections->take(5)->implode(', ').$overflow;
    }

    private static function hoursLabel(int $minutes): string
    {
        $hours = $minutes / 60;
        $text = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');

        return $text.' '.($hours == 1 ? 'hour' : 'hours');
    }
}
