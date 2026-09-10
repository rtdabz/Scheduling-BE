<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\YearLevelFeasibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pinning a course to one day is a per-department choice, so the capacity check
 * must be driven entirely by what each department has configured: exact when a
 * pin exists, and silent when none does.
 */
class ForcedDayCapacityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_forced_day_configured_is_never_blocked(): void
    {
        $context = $this->scaffold(sectionCount: 30, forcedDay: null);

        $this->assertSame(
            [],
            $this->check($context),
            'A department that pins nothing must not be blocked by the forced-day check.',
        );
    }

    public function test_a_pinned_course_that_fits_is_not_blocked(): void
    {
        // Three legal starts at a field concurrency of 3 leaves room for nine.
        $context = $this->scaffold(sectionCount: 9, forcedDay: 'Saturday');

        $this->assertSame([], $this->check($context));
    }

    public function test_an_oversubscribed_pinned_course_is_blocked_with_exact_numbers(): void
    {
        $context = $this->scaffold(sectionCount: 21, forcedDay: 'Saturday');

        $blocking = $this->check($context);

        $this->assertCount(1, $blocking);
        $this->assertSame('forced_day_capacity_exceeded', $blocking[0]['code']);

        $context = $blocking[0]['context'];
        $this->assertSame('NSTP 1', $context['course_code']);
        $this->assertSame('Saturday', $context['forced_day']);
        $this->assertSame(21, $context['required_placements']);
        // A three-hour field course must end by 17:00, so 16:00 is not a legal
        // start: three starts, not the four the raw grid would suggest.
        $this->assertSame(3, $context['start_times']);
        $this->assertSame(9, $context['available_placements']);
        $this->assertSame(7, $context['required_concurrency']);
    }

    public function test_raising_the_field_limit_clears_the_block(): void
    {
        $context = $this->scaffold(sectionCount: 21, forcedDay: 'Saturday', fieldSlotLimit: 7);

        $this->assertSame(
            [],
            $this->check($context),
            'Three starts at a concurrency of seven covers 21 sections.',
        );
    }

    public function test_a_laboratory_block_longer_than_the_day_is_reported_against_its_own_course(): void
    {
        // Six laboratory units convert to an eighteen-hour block against a
        // thirteen-and-a-half hour teaching day.
        $context = $this->scaffold(sectionCount: 2, forcedDay: null, splitCourse: [2, 6]);

        $blocking = array_values(array_filter(
            app(YearLevelFeasibilityService::class)->check($context['sections'], $context['configs']),
            static fn (array $item): bool => ($item['code'] ?? '') === 'component_duration_exceeds_day',
        ));

        $this->assertCount(1, $blocking, 'An unschedulable laboratory block was not reported.');
        $this->assertSame('IT 122', $blocking[0]['context']['course_code']);
        $this->assertSame('laboratory', $blocking[0]['context']['component']);
        $this->assertStringContainsString('18 hours', $blocking[0]['message']);
        $this->assertStringContainsString('13.5 hours', $blocking[0]['message']);
    }

    public function test_a_normal_laboratory_block_is_not_reported(): void
    {
        $context = $this->scaffold(sectionCount: 2, forcedDay: null, splitCourse: [2, 1]);

        $this->assertSame(
            [],
            array_values(array_filter(
                app(YearLevelFeasibilityService::class)->check($context['sections'], $context['configs']),
                static fn (array $item): bool => ($item['code'] ?? '') === 'component_duration_exceeds_day',
            )),
        );
    }

    /** @param array{sections: list<Sections>, configs: array<int, array<string, mixed>>} $context */
    private function check(array $context): array
    {
        return array_values(array_filter(
            app(YearLevelFeasibilityService::class)->check($context['sections'], $context['configs']),
            static fn (array $item): bool => ($item['code'] ?? '') === 'forced_day_capacity_exceeded',
        ));
    }

    /**
     * @param  array{0: int, 1: int}|null  $splitCourse  lecture and laboratory units
     * @return array{sections: list<Sections>, configs: array<int, array<string, mixed>>}
     */
    private function scaffold(int $sectionCount, ?string $forcedDay, int $fieldSlotLimit = 3, ?array $splitCourse = null): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);

        $department = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
            'field_slot_limit' => $fieldSlotLimit,
            'field_evening_schedule_enabled' => false,
            'lecture_lab_schedule_override_enabled' => $splitCourse !== null,
        ]);

        Rooms::create([
            'room_code' => 'FIELD', 'building' => 'Campus', 'room_type' => 'field',
            'status' => 'available', 'department_id' => null, 'max_concurrent_classes' => 3,
        ]);
        foreach (range(1, 20) as $i) {
            Rooms::create([
                'room_code' => "LEC-{$i}", 'building' => 'Main', 'room_type' => 'lecture',
                'status' => 'available', 'department_id' => $department->id, 'max_concurrent_classes' => 1,
            ]);
        }

        $curriculum = Curriculum::create([
            'name' => 'IT Curriculum', 'department_id' => $department->id,
            'code' => 'IT-2026', 'effective_school_year' => '2026-2027', 'status' => 'active',
        ]);

        // A three-hour NSTP course: field by category, shared across departments.
        $course = Course::create([
            'course_code' => 'NSTP 1', 'course_name' => 'National Service Training Program',
            'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'room_type_required' => 'lecture',
            'year_level' => '1', 'semester' => '1st',
            'department_id' => null, 'status' => 'active',
        ]);
        $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);

        if ($forcedDay !== null) {
            DB::table('department_forced_course_days')->insert([
                'department_id' => $department->id,
                'course_id' => $course->id,
                'day' => $forcedDay,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $splitCourseId = null;
        if ($splitCourse !== null) {
            [$lectureUnits, $laboratoryUnits] = $splitCourse;
            $split = Course::create([
                'course_code' => 'IT 122', 'course_name' => 'Capstone',
                'lecture_hours' => $lectureUnits, 'lab_hours' => $laboratoryUnits,
                'units' => $lectureUnits + $laboratoryUnits,
                'course_category' => 'major', 'room_type_required' => 'laboratory',
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'status' => 'active',
            ]);
            $curriculum->courses()->attach($split->id, ['year_level' => 1, 'semester' => 1]);
            $splitCourseId = (int) $split->id;
        }

        $sections = [];
        $configs = [];
        for ($s = 1; $s <= $sectionCount; $s++) {
            $section = Sections::create([
                'section_name' => "IT 1-{$s}", 'year_level' => '1', 'semester' => '1st',
                'department_id' => $department->id, 'term_id' => $term->id, 'status' => 'active',
            ]);
            $sections[] = $section;
            $configs[(int) $section->id] = [
                'course_ids' => array_values(array_filter([(int) $course->id, $splitCourseId])),
                'mode' => 'on-site',
                'selected_split_session_course_ids' => array_values(array_filter([$splitCourseId])),
                'balanced_split_course_ids' => [],
                'preferred_patterns' => [],
                'delivery_modes_by_course_id' => [],
            ];
        }

        return ['sections' => $sections, 'configs' => $configs];
    }
}
