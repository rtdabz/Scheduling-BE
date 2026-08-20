<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\Scheduling\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the server side of the day/category contract that the browser mirrors in
 * wicars-ui/src/pages/ClassSchedules/SchedulerPanel/hooks/useConflict.ts.
 *
 * Audit finding #2 was that only the server enforced these rules, so the
 * placement modal reported a valid placement that the save then rejected. The
 * expectations here and in useConflict.test.ts must be kept in step.
 */
class DayCategoryConstraintParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_nstp_courses_may_use_any_day_including_sunday(): void
    {
        $rules = $this->violationRulesForEachDay($this->course('CWTS1', 'Civic Welfare Training', 'major', 'field'));

        foreach ($rules as $day => $dayRules) {
            $this->assertNotContains('nstp_day_constraint', $dayRules, "NSTP should be allowed on {$day}");
            $this->assertNotContains('field_day_constraint', $dayRules, "NSTP should not hit the field rule on {$day}");
            $this->assertNotContains('major_sunday_mode_constraint', $dayRules, "NSTP should not hit the Sunday rule on {$day}");
        }
    }

    public function test_non_nstp_field_courses_are_limited_to_weekdays(): void
    {
        $rules = $this->violationRulesForEachDay($this->course('PATHFIT1', 'Movement Competency', 'major', 'field'));

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
            $this->assertNotContains('field_day_constraint', $rules[$day], "Field course should be allowed on {$day}");
        }

        foreach (['Saturday', 'Sunday'] as $day) {
            $this->assertContains('field_day_constraint', $rules[$day], "Field course should be rejected on {$day}");
        }
    }

    public function test_minor_courses_are_limited_to_monday_through_saturday(): void
    {
        $rules = $this->violationRulesForEachDay($this->course('GEC1', 'Understanding the Self', 'minor', 'lecture'));

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            $this->assertNotContains('minor_day_constraint', $rules[$day], "Minor course should be allowed on {$day}");
        }

        $this->assertContains('minor_day_constraint', $rules['Sunday']);
    }

    public function test_major_courses_on_sunday_require_online_delivery(): void
    {
        [$term, $department, $section, $room] = $this->fixture();
        $course = $this->course('IT101', 'Intro to Computing', 'major', 'lecture');

        $onSite = $this->rules($this->attempt($term, $department, $section, $course, 'Sunday', 'on-site', $room->id));
        $this->assertContains('major_sunday_mode_constraint', $onSite);

        $online = $this->rules($this->attempt($term, $department, $section, $course, 'Sunday', 'online', null));
        $this->assertNotContains('major_sunday_mode_constraint', $online);
    }

    public function test_sunday_rule_can_be_disabled_per_department(): void
    {
        [$term, $department, $section, $room] = $this->fixture();
        $department->update(['sunday_online_only_enabled' => false]);
        $course = $this->course('IT101', 'Intro to Computing', 'major', 'lecture');

        $rules = $this->rules($this->attempt($term, $department, $section, $course, 'Sunday', 'on-site', $room->id));

        $this->assertNotContains('major_sunday_mode_constraint', $rules);
    }

    public function test_field_courses_must_end_by_five_pm_unless_the_department_opts_in(): void
    {
        [$term, $department, $section] = $this->fixture();
        $field = $this->fieldRoom();
        $course = $this->course('PATHFIT9', 'Movement Competency', 'major', 'field');

        $evening = $this->attempt($term, $department, $section, $course, 'Monday', 'field', $field->id);
        $evening['start_time'] = '17:00';
        $evening['end_time'] = '18:00';

        // Enforced only by CspSolver before, so a manual drag could ignore the
        // limit the Settings page promises (audit finding #41).
        $this->assertContains('field_evening_window', $this->rules($evening));

        $department->update(['field_evening_schedule_enabled' => true]);
        $this->assertNotContains('field_evening_window', $this->rules($evening));
    }

    public function test_daytime_field_placements_are_unaffected(): void
    {
        [$term, $department, $section] = $this->fixture();
        $field = $this->fieldRoom();
        $course = $this->course('PATHFIT8', 'Movement Competency', 'major', 'field');

        $daytime = $this->attempt($term, $department, $section, $course, 'Monday', 'field', $field->id);
        $daytime['start_time'] = '08:00';
        $daytime['end_time'] = '10:00';

        $this->assertNotContains('field_evening_window', $this->rules($daytime));
    }

    public function test_non_field_courses_may_run_into_the_evening(): void
    {
        [$term, $department, $section, $room] = $this->fixture();
        $course = $this->course('IT909', 'Evening Lecture', 'major', 'lecture');

        $evening = $this->attempt($term, $department, $section, $course, 'Monday', 'on-site', $room->id);
        $evening['start_time'] = '17:00';
        $evening['end_time'] = '18:00';

        $this->assertNotContains('field_evening_window', $this->rules($evening));
    }
    /** @return array<string, list<string>> */
    private function violationRulesForEachDay(Course $course): array
    {
        [$term, $department, $section, $room] = $this->fixture();
        $isFieldLike = $course->room_type_required === 'field';
        $result = [];

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $room = $isFieldLike ? $this->fieldRoom() : $room;
            $result[$day] = $this->rules(
                $this->attempt($term, $department, $section, $course, $day, $isFieldLike ? 'field' : 'on-site', $room->id)
            );
        }

        return $result;
    }

    /** @return list<string> */
    private function rules(array $attempt): array
    {
        return array_values(array_map(
            static fn (array $violation): string => (string) ($violation['rule'] ?? ''),
            app(RuleEngine::class)->validate($attempt),
        ));
    }

    private function attempt(
        Terms $term,
        Departments $department,
        Sections $section,
        Course $course,
        string $day,
        string $mode,
        ?int $roomId,
    ): array {
        return [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $department->id,
            'room_id' => $roomId,
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => $mode,
        ];
    }

    /** @return array{0: Terms, 1: Departments, 2: Sections, 3: Rooms} */
    private function fixture(): array
    {
        $term = Terms::firstOrCreate(
            ['academic_year' => '2026-2027', 'semester' => '1st'],
            ['is_active' => true, 'is_enabled' => true],
        );
        $department = Departments::firstOrCreate(
            ['department_code' => 'PAR'],
            ['department_name' => 'Parity Dept'],
        );
        $section = Sections::firstOrCreate(
            ['section_name' => 'PAR-1A', 'department_id' => $department->id, 'term_id' => $term->id],
            ['year_level' => '1', 'semester' => '1st', 'status' => 'active'],
        );
        $room = Rooms::firstOrCreate(
            ['room_code' => 'PAR101'],
            ['room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id],
        );

        return [$term, $department, $section, $room];
    }

    private function fieldRoom(): Rooms
    {
        return Rooms::firstOrCreate(
            ['room_code' => 'FIELD'],
            ['room_type' => 'field', 'status' => 'available', 'department_id' => null, 'max_concurrent_classes' => 5],
        );
    }

    private function course(string $code, string $name, string $category, string $roomType): Course
    {
        return Course::firstOrCreate(
            ['course_code' => $code],
            [
                'course_name' => $name,
                'lecture_hours' => $roomType === 'field' ? 2 : 3,
                'lab_hours' => 0,
                'units' => 3,
                'course_category' => $category,
                'room_type_required' => $roomType,
                'year_level' => '1',
                'semester' => '1st',
                'department_id' => null,
                'status' => 'active',
            ],
        );
    }
}
