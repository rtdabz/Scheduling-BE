<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Engine\RuleEngine;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\TimeslotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the server side of the day/category contract that the browser mirrors in
 * wicars-ui/src/pages/ClassSchedules/SchedulerPanel/hooks/useConflict.ts: every
 * course category may use every day. Field courses were once Monday-Friday,
 * minors Monday-Saturday, and a Sunday major had to be online -- those limits
 * (and the department's `sunday_online_only_enabled` switch) are gone, along
 * with the `field_day_constraint`, `minor_day_constraint` and
 * `major_sunday_mode_constraint` rules that enforced them (see
 * MeetingDayRule's class doc). Only a Required Day (`forced_course_day`,
 * covered by ForcedDayCapacityCheckTest and friends) or a declared meeting
 * pattern narrows a course's days now.
 */
class DayCategoryConstraintParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_courses_may_meet_any_day_of_the_week(): void
    {
        $nstp = $this->violationRulesForEachDay($this->course('CWTS1', 'Civic Welfare Training', 'major', 'field'));
        $nonNstp = $this->violationRulesForEachDay($this->course('PATHFIT1', 'Movement Competency', 'major', 'field'));

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $this->assertNotContains('field_day_constraint', $nstp[$day], "NSTP should be allowed on {$day}");
            $this->assertNotContains('field_day_constraint', $nonNstp[$day], "Field course should be allowed on {$day}");
        }
    }

    public function test_minor_courses_may_meet_any_day_of_the_week(): void
    {
        $rules = $this->violationRulesForEachDay($this->course('GEC1', 'Understanding the Self', 'minor', 'lecture'));

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $this->assertNotContains('minor_day_constraint', $rules[$day], "Minor course should be allowed on {$day}");
        }
    }

    public function test_major_courses_may_meet_on_site_on_sunday(): void
    {
        [$semester, $department, $section, $room] = $this->fixture();
        $course = $this->course('IT101', 'Intro to Computing', 'major', 'lecture');

        $onSite = $this->rules($this->attempt($semester, $department, $section, $course, 'Sunday', 'on-site', $room->id));
        $this->assertNotContains('major_sunday_mode_constraint', $onSite);

        $online = $this->rules($this->attempt($semester, $department, $section, $course, 'Sunday', 'online', null));
        $this->assertNotContains('major_sunday_mode_constraint', $online);
    }

    public function test_field_courses_must_end_by_the_institution_field_end_time(): void
    {
        [$semester, $department, $section] = $this->fixture();
        $field = $this->fieldRoom();
        $course = $this->course('PATHFIT9', 'Movement Competency', 'major', 'field');

        $evening = $this->attempt($semester, $department, $section, $course, 'Monday', 'field', $field->id);
        $evening['start_time'] = '17:00';
        $evening['end_time'] = '18:00';

        // Enforced only by CspSolver before, so a manual drag could ignore the
        // limit the Settings page promises (audit finding #41).
        $this->assertContains('field_evening_window', $this->rules($evening));

        // The cut-off is the VPAA setting, not a hardcoded 5:00 PM.
        app(TimeslotService::class)->settings()->update(['field_end_time' => '18:00:00']);
        SchedulingPolicy::clearTimeCache();
        $this->assertNotContains('field_evening_window', $this->rules($evening));

        $evening['start_time'] = '17:30';
        $evening['end_time'] = '18:30';
        $this->assertContains('field_evening_window', $this->rules($evening));
    }

    public function test_daytime_field_placements_are_unaffected(): void
    {
        [$semester, $department, $section] = $this->fixture();
        $field = $this->fieldRoom();
        $course = $this->course('PATHFIT8', 'Movement Competency', 'major', 'field');

        $daytime = $this->attempt($semester, $department, $section, $course, 'Monday', 'field', $field->id);
        $daytime['start_time'] = '08:00';
        $daytime['end_time'] = '10:00';

        $this->assertNotContains('field_evening_window', $this->rules($daytime));
    }

    public function test_non_field_courses_may_run_into_the_evening(): void
    {
        [$semester, $department, $section, $room] = $this->fixture();
        $course = $this->course('IT909', 'Evening Lecture', 'major', 'lecture');

        $evening = $this->attempt($semester, $department, $section, $course, 'Monday', 'on-site', $room->id);
        $evening['start_time'] = '17:00';
        $evening['end_time'] = '18:00';

        $this->assertNotContains('field_evening_window', $this->rules($evening));
    }
    /** @return array<string, list<string>> */
    private function violationRulesForEachDay(Course $course): array
    {
        [$semester, $department, $section, $room] = $this->fixture();
        $isFieldLike = $course->room_type_required === 'field';
        $result = [];

        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $room = $isFieldLike ? $this->fieldRoom() : $room;
            $result[$day] = $this->rules(
                $this->attempt($semester, $department, $section, $course, $day, $isFieldLike ? 'field' : 'on-site', $room->id)
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
        Semester $semester,
        Departments $department,
        Sections $section,
        Course $course,
        string $day,
        string $mode,
        ?int $roomId,
    ): array {
        return [
            'semester_id' => $semester->id,
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

    /** @return array{0: Semester, 1: Departments, 2: Sections, 3: Rooms} */
    private function fixture(): array
    {
        $semester = Semester::firstOrCreate(
            ['academic_year' => '2026-2027', 'semester' => '1st'],
            ['is_active' => true, 'is_enabled' => true],
        );
        $department = Departments::firstOrCreate(
            ['department_code' => 'PAR'],
            ['department_name' => 'Parity Dept'],
        );
        $section = Sections::firstOrCreate(
            ['section_name' => 'PAR-1A', 'department_id' => $department->id, 'semester_id' => $semester->id],
            ['year_level' => '1', 'semester' => '1st', 'status' => 'active'],
        );
        $room = Rooms::firstOrCreate(
            ['room_code' => 'PAR101'],
            ['room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id],
        );

        return [$semester, $department, $section, $room];
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
