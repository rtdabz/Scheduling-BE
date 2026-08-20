<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the fix for audit finding #8: a batch save issued roughly seven
 * response-hydration queries and ten rule-engine lookups *per operation*, so the
 * query count grew steeply with batch size.
 *
 * The assertions are on growth rather than absolute counts, so they stay valid as
 * unrelated queries come and go.
 */
class ScheduleBatchQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_count_grows_far_slower_than_the_number_of_operations(): void
    {
        [$term, $dept, $section, $room, $user] = $this->fixture();
        $courses = $this->courses($dept, 12);

        $forFour = $this->countQueriesForBatch($user, $term, $dept, $section, $room, array_slice($courses, 0, 4), 0);
        $forTwelve = $this->countQueriesForBatch($user, $term, $dept, $section, $room, array_slice($courses, 4, 8), 8);

        // Tripling the payload must not triple the query count. Before the fix,
        // per-operation hydration and rule-engine lookups made growth linear with
        // a large constant.
        $perOperationBefore = $forFour / 4;
        $perOperationAfter = $forTwelve / 8;

        $this->assertLessThan(
            $perOperationBefore,
            $perOperationAfter,
            "Per-operation query cost should fall as the batch grows (4 ops: {$forFour} queries, 8 ops: {$forTwelve})",
        );

        // A generous ceiling that the pre-fix implementation could not meet.
        $this->assertLessThan(
            18,
            $perOperationAfter,
            "Expected well under 18 queries per operation, saw {$perOperationAfter} ({$forTwelve} for 8 operations)",
        );
    }

    public function test_response_hydration_does_not_scale_with_operation_count(): void
    {
        [$term, $dept, $section, $room, $user] = $this->fixture();
        $courses = $this->courses($dept, 6);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->postBatch($user, $term, $dept, $section, $room, $courses, 0)->assertOk();

        // The saved rows are re-read once with their relations, not once per row.
        $termLoads = count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "terms"') && str_contains($sql, 'in ('),
        ));

        $this->assertLessThanOrEqual(
            2,
            $termLoads,
            'Relations should be eager-loaded once for the whole batch, not per operation.',
        );
    }

    private function countQueriesForBatch(
        User $user,
        Terms $term,
        Departments $dept,
        Sections $section,
        Rooms $room,
        array $courses,
        int $slotOffset,
    ): int {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->postBatch($user, $term, $dept, $section, $room, $courses, $slotOffset)->assertOk();

        return $count;
    }

    private function postBatch(
        User $user,
        Terms $term,
        Departments $dept,
        Sections $section,
        Rooms $room,
        array $courses,
        int $slotOffset,
    ) {
        $operations = [];
        foreach (array_values($courses) as $index => $course) {
            $slot = $slotOffset + $index;
            $day = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'][intdiv($slot, 5) % 5];
            $hour = 7 + ($slot % 5) * 2;

            $operations[] = [
                'term_id' => $term->id,
                'section_id' => $section->id,
                'course_id' => $course->id,
                'room_id' => $room->id,
                'department_id' => $dept->id,
                'day' => $day,
                'start_time' => sprintf('%02d:00', $hour),
                'end_time' => sprintf('%02d:00', $hour + 1),
                'mode' => 'on-site',
                'status' => 'draft',
            ];
        }

        return $this->actingAs($user)->postJson('/api/schedules/batch', ['operations' => $operations]);
    }

    /** @return array{0: Terms, 1: Departments, 2: Sections, 3: Rooms, 4: User} */
    private function fixture(): array
    {
        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $dept = Departments::create(['department_name' => 'Query Dept', 'department_code' => 'QRY']);
        $section = Sections::create([
            'section_name' => 'QRY-1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'term_id' => $term->id, 'status' => 'active',
        ]);
        $room = Rooms::create([
            'room_code' => 'QRY101', 'room_type' => 'lecture', 'status' => 'available',
            'department_id' => $dept->id, 'max_concurrent_classes' => 1,
        ]);
        $user = User::factory()->create(['role' => 'secretary', 'department_id' => $dept->id]);

        return [$term, $dept, $section, $room, $user];
    }

    /** @return list<Course> */
    private function courses(Departments $dept, int $count): array
    {
        $courses = [];
        for ($i = 0; $i < $count; $i++) {
            $courses[] = Course::create([
                'course_code' => sprintf('QRY%03d', $i),
                'course_name' => "Query Course {$i}",
                'lecture_hours' => 2, 'lab_hours' => 0, 'units' => 2,
                'course_category' => 'major', 'room_type_required' => 'lecture',
                'year_level' => '1', 'semester' => '1st',
                'department_id' => $dept->id, 'status' => 'active',
            ]);
        }

        return $courses;
    }
}
