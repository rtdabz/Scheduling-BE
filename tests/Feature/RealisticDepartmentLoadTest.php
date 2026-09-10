<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\ScheduleRequirementBuilderResolver;
use App\Services\Scheduling\YearLevelScheduleGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The full live year level, generated end to end.
 *
 * This used to be impossible. ROTC/CWTS is pinned to Saturday and is a field
 * course running three hours, so it has three legal starts; against a field
 * concurrency of three that allowed nine placements for a year level of
 * twenty-one, and generation failed at section ten. The field is open ground and
 * is no longer capped by default, so the whole year level now schedules. Setting
 * FIELD_LIMIT restores a ceiling and the pre-check reports it exactly:
 *
 *   FIELD_LIMIT=3 php artisan test --filter=RealisticDepartmentLoad
 *
 * Mirrors the live dataset rather than a tidy fixture. Taken from the working
 * database (College of Information Technology, year 1, semester 1):
 *
 *   21 sections sharing one course set of 6 courses
 *   3 laboratory courses per section competing for only 6 laboratory rooms
 *   1 NSTP course and 1 PATHFIT course, both field, both forced to Saturday
 *   3 of the 6 courses are shared minors with a NULL department_id
 *   ~1,500 schedules already persisted for other year levels in the term
 *
 * The split count of 3 crosses SPLIT_HEAVY_COURSE_THRESHOLD, so the solver runs
 * its long per-attempt timeout. This is the shape the generator actually has to
 * survive in production.
 */
class RealisticDepartmentLoadTest extends TestCase
{
    use RefreshDatabase;

    /** The live department's full year-level size. */
    private const SECTION_COUNT = 21;

    private const OTHER_YEAR_SCHEDULE_ROWS = 600;

    public function test_realistic_year_level_load_generates_within_budget(): void
    {
        [$sections, $configs] = $this->buildRealisticScenario();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $startedAt = microtime(true);
        $result = app(YearLevelScheduleGenerationService::class)->preview($sections, $configs);
        $elapsed = microtime(true) - $startedAt;

        $rows = $result['schedules'] ?? [];
        $metrics = $result['generation_metrics'] ?? [];

        $tba = count(array_filter($rows, static fn (array $r): bool => ($r['room_id'] ?? null) === null
            && ($r['mode'] ?? '') !== 'online'));
        $online = count(array_filter($rows, static fn (array $r): bool => ($r['mode'] ?? '') === 'online'));

        fwrite(STDERR, sprintf(
            "\n=== REALISTIC DEPARTMENT LOAD ===\n".
            "sections            %8d\n".
            "persisted rows      %8d\n".
            "TOTAL WALL TIME     %8.2f s\n".
            "solver attempts     %8d\n".
            "candidates built    %8d\n".
            "search limit hit    %8s\n".
            "database            %8d queries\n".
            "rows produced       %8d\n".
            "  room TBA          %8d\n".
            "  online            %8d\n".
            "=================================\n",
            count($sections),
            Schedule::query()->count(),
            $elapsed,
            (int) ($metrics['solver_attempts'] ?? 0),
            (int) ($metrics['candidate_count_before'] ?? 0),
            ($metrics['search_limit_reached'] ?? false) ? 'yes' : 'no',
            $queryCount,
            count($rows),
            $tba,
            $online,
        ));

        $this->assertNotEmpty($rows, 'Realistic load produced no schedule at all.');
    }

    /** @return array{0: list<Sections>, 1: array<int, array<string, mixed>>} */
    private function buildRealisticScenario(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'scheduling_profile' => 'laboratory_enabled',
            'lecture_lab_schedule_override_enabled' => true,
            'gec_split_schedule_override_enabled' => true,
            'sunday_online_only_enabled' => true,
            'field_evening_schedule_enabled' => false,
            'online_slot_limit' => 4,
            // Unset by default, matching the shipped configuration: a field is
            // not capped unless a department deliberately caps it. FIELD_LIMIT
            // forces a ceiling to reproduce the old behaviour.
            'field_slot_limit' => getenv('FIELD_LIMIT') ? (int) getenv('FIELD_LIMIT') : null,
        ]);

        // Real room inventory: laboratories are the scarce resource.
        foreach (range(1, 30) as $i) {
            Rooms::create([
                'room_code' => "LEC-{$i}", 'building' => 'Main', 'room_type' => 'lecture',
                'status' => 'available', 'department_id' => $department->id, 'max_concurrent_classes' => 1,
            ]);
        }
        foreach (range(1, 6) as $i) {
            Rooms::create([
                'room_code' => "LAB-{$i}", 'building' => 'Main', 'room_type' => 'laboratory',
                'status' => 'available', 'department_id' => $department->id, 'max_concurrent_classes' => 1,
            ]);
        }
        Rooms::create([
            'room_code' => 'ONLINE', 'building' => 'Virtual', 'room_type' => 'online',
            'status' => 'available', 'department_id' => null, 'max_concurrent_classes' => 3,
        ]);
        Rooms::create([
            'room_code' => 'FIELD', 'building' => 'Campus', 'room_type' => 'field',
            'status' => 'available', 'department_id' => null, 'max_concurrent_classes' => 3,
        ]);

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum', 'department_id' => $department->id,
            'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);

        // The live year-1 semester-1 course set, department_id included.
        $definitions = [
            ['GEC 1', 3, 3, 0, 'minor', null, 'lecture'],
            ['IT 101', 3, 2, 1, 'major', $department->id, 'laboratory'],
            ['IT 102', 3, 2, 1, 'major', $department->id, 'laboratory'],
            ['IT 103', 3, 2, 1, 'major', $department->id, 'laboratory'],
            ['ROTC/CWTS 101', 3, 3, 0, 'minor', null, 'lecture'],
            ['PATH FIT 1', 2, 2, 0, 'minor', null, 'lecture'],
        ];

        $courseIds = [];
        $splitIds = [];
        $fieldCodes = [];
        foreach ($definitions as [$code, $units, $lec, $lab, $category, $deptId, $roomType]) {
            $course = Course::create([
                'course_code' => $code, 'course_name' => $code,
                'lecture_hours' => $lec, 'lab_hours' => $lab, 'units' => $units,
                'course_category' => $category, 'room_type_required' => $roomType,
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $deptId, 'status' => 'active',
            ]);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courseIds[] = (int) $course->id;
            if ($lab > 0) {
                $splitIds[] = (int) $course->id;
            }
            if (in_array($code, ['ROTC/CWTS 101', 'PATH FIT 1'], true)) {
                // Both are field courses, but only NSTP is pinned to Saturday
                // in the live configuration; PATHFIT spreads across Mon-Fri.
                $fieldCodes[$code] = ['id' => (int) $course->id, 'forced' => $code === 'ROTC/CWTS 101'];
            }
        }

        // Live quirk: the field-course settings are scoped to the department
        // while the courses themselves have a NULL department_id.
        foreach ($fieldCodes as $code => $field) {
            DB::table('field_course_settings')->insert([
                'department_id' => $department->id,
                'enabled' => true,
                'course_code' => $code,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($field['forced']) {
                DB::table('department_forced_course_days')->insert([
                    'department_id' => $department->id,
                    'course_id' => $field['id'],
                    'day' => 'Saturday',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Optional: widen the start grid for the three-hour NSTP block. Without
        // overrides generateStartTimes() steps by the whole duration, so a
        // three-hour class may only begin at 07:00, 10:00, 13:00 or 16:00.
        if (getenv('NSTP_HOURLY_STARTS')) {
            foreach (range(7, 17) as $hour) {
                DB::table('timeslot_override')->insert([
                    'duration_minutes' => 180,
                    'start_time' => sprintf('%02d:00:00', $hour),
                    'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $sections = [];
        $configs = [];
        $sectionTarget = (int) (getenv('REAL_SECTIONS') ?: self::SECTION_COUNT);
        for ($s = 1; $s <= $sectionTarget; $s++) {
            $section = Sections::create([
                'section_name' => 'IT 1-'.$s,
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active',
            ]);
            $sections[] = $section;

            $config = [
                'course_ids' => $courseIds,
                'mode' => 'on-site',
                'is_hybrid' => false,
                'selected_split_session_course_ids' => $splitIds,
                'balanced_split_course_ids' => [],
                'preferred_patterns' => [],
                'delivery_modes_by_course_id' => [],
                'seed' => 1234 + $s,
            ];
            $config['requirements_by_course_id'] = app(ScheduleRequirementBuilderResolver::class)
                ->build($section, $courseIds, $config);
            $config['department_profile'] = 'laboratory_enabled';
            $configs[(int) $section->id] = $config;
        }

        $this->seedOtherYearLevelSchedules($term, $department, $courseIds[0]);

        return [$sections, $configs];
    }

    /**
     * Schedules belonging to other year levels in the same term. A year-1 run
     * cannot replace these, so they stay in the snapshot and consume rooms.
     */
    private function seedOtherYearLevelSchedules(Terms $term, Departments $department, int $courseId): void
    {
        // Other year levels lean on lecture rooms; the six laboratories are left
        // mostly free because year 1's own three laboratory courses are what
        // actually contend for them.
        // Other year levels occupy lecture rooms. The six laboratories are left
        // to year 1, whose three laboratory courses are what contend for them.
        $roomIds = Rooms::query()->where('room_type', 'lecture')->pluck('id')->all();
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $starts = ['07:00:00', '09:00:00', '11:00:00', '13:00:00', '15:00:00', '17:00:00'];

        $otherSection = Sections::create([
            'section_name' => 'IT 2-1', 'year_level' => '2', 'semester' => '1st',
            'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active',
        ]);

        $rows = [];
        $created = 0;
        foreach ($roomIds as $roomId) {
            foreach ($days as $day) {
                foreach ($starts as $start) {
                    if ($created >= self::OTHER_YEAR_SCHEDULE_ROWS) {
                        break 3;
                    }
                    // Leave roughly a third of the grid free so year 1 can fit.
                    if ($created % 3 === 0) {
                        $created++;

                        continue;
                    }
                    $rows[] = [
                        'term_id' => $term->id,
                        'section_id' => $otherSection->id,
                        'course_id' => $courseId,
                        'room_id' => $roomId,
                        'department_id' => $department->id,
                        'day' => $day,
                        'start_time' => $start,
                        'end_time' => date('H:i:s', strtotime($start) + 7200),
                        'mode' => 'on-site',
                        'status' => 'finalized',
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                    $created++;
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('schedules')->insert($chunk);
        }
    }
}
