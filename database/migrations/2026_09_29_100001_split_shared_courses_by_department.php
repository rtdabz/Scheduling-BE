<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses are department-owned: GEC 1 in IT and GEC 1 in BA are two records.
 *
 * Until now a minor could be a "shared" course with no department, one row
 * every department's curriculum pointed at, so an edit from one department
 * (or re-adding the code in another) changed it for all of them. Each shared
 * course is split here into one copy per department that uses it — through a
 * curriculum, a scheduled class or a course rule — and those references move
 * to the department's own copy. The first department keeps the original row,
 * so its ids do not change. A shared course no department uses is archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $shared = DB::table('courses')->whereNull('department_id')->orderBy('id')->get();

            foreach ($shared as $course) {
                $departmentIds = $this->departmentsUsing((int) $course->id);

                if ($departmentIds === []) {
                    if ($course->deleted_at === null) {
                        DB::table('courses')->where('id', $course->id)->update(['deleted_at' => now()]);
                    }

                    continue;
                }

                foreach ($departmentIds as $index => $departmentId) {
                    $targetId = $index === 0
                        ? $this->claim($course, $departmentId)
                        : $this->copyFor($course, $departmentId);

                    if ($targetId !== (int) $course->id) {
                        $this->repoint((int) $course->id, $targetId, $departmentId);
                    }
                }
            }
        });

        // Shared codes no longer exist, so their uniqueness guard goes too.
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('courses', 'shared_course_code')) {
            DB::statement('ALTER TABLE `courses` DROP INDEX `courses_shared_code_unique`');
            DB::statement('ALTER TABLE `courses` DROP COLUMN `shared_course_code`');
        }
    }

    public function down(): void
    {
        // The split is not reversed: merging department copies back together
        // would again let one department's edits change another's courses.
    }

    /** @return list<int> Departments referencing the course, lowest id first. */
    private function departmentsUsing(int $courseId): array
    {
        $viaCurricula = DB::table('curriculum_course')
            ->join('curriculum', 'curriculum.id', '=', 'curriculum_course.curriculum_id')
            ->where('curriculum_course.course_id', $courseId)
            ->whereNotNull('curriculum.department_id')
            ->pluck('curriculum.department_id');

        $viaSchedules = DB::table('schedules')
            ->join('sections', 'sections.id', '=', 'schedules.section_id')
            ->where('schedules.course_id', $courseId)
            ->whereNotNull('sections.department_id')
            ->pluck('sections.department_id');

        $viaRules = DB::table('department_course_rules')
            ->where('course_id', $courseId)
            ->pluck('department_id');

        return $viaCurricula->merge($viaSchedules)->merge($viaRules)
            ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** The first department takes the shared row itself, unless it already has the code. */
    private function claim(object $course, int $departmentId): int
    {
        $existing = $this->departmentCourseId($course->course_code, $departmentId);
        if ($existing !== null) {
            DB::table('courses')->where('id', $course->id)->update(['deleted_at' => $course->deleted_at ?? now()]);

            return $existing;
        }

        DB::table('courses')->where('id', $course->id)->update(['department_id' => $departmentId]);

        return (int) $course->id;
    }

    private function copyFor(object $course, int $departmentId): int
    {
        $existing = $this->departmentCourseId($course->course_code, $departmentId);
        if ($existing !== null) {
            return $existing;
        }

        $row = collect((array) $course)->except(['id', 'shared_course_code'])->all();
        $row['department_id'] = $departmentId;
        $row['created_at'] = now();
        $row['updated_at'] = now();

        return (int) DB::table('courses')->insertGetId($row);
    }

    private function departmentCourseId(string $code, int $departmentId): ?int
    {
        $id = DB::table('courses')
            ->where('course_code', $code)
            ->where('department_id', $departmentId)
            ->orderByRaw('deleted_at IS NOT NULL')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** Moves one department's references from the shared course to its own copy. */
    private function repoint(int $fromId, int $toId, int $departmentId): void
    {
        $curriculumIds = DB::table('curriculum')->where('department_id', $departmentId)->pluck('id');

        // A curriculum already holding the department's copy keeps that link.
        $alreadyLinked = DB::table('curriculum_course')
            ->whereIn('curriculum_id', $curriculumIds)
            ->where('course_id', $toId)
            ->pluck('curriculum_id');
        DB::table('curriculum_course')
            ->whereIn('curriculum_id', $alreadyLinked)
            ->where('course_id', $fromId)
            ->delete();
        DB::table('curriculum_course')
            ->whereIn('curriculum_id', $curriculumIds)
            ->where('course_id', $fromId)
            ->update(['course_id' => $toId]);

        DB::table('schedules')
            ->whereIn('section_id', DB::table('sections')->where('department_id', $departmentId)->select('id'))
            ->where('course_id', $fromId)
            ->update(['course_id' => $toId]);

        DB::table('department_course_rules')
            ->where('department_id', $departmentId)
            ->where('course_id', $fromId)
            ->update(['course_id' => $toId]);
    }
};
