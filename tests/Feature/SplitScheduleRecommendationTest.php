<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Terms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitScheduleRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_recommendation_prioritizes_same_day_and_time_then_other_days_at_same_time()
    {
        // 0. Authenticate User
        $user = \App\Models\User::create([
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'secretary',
        ]);
        $this->actingAs($user);

        // 1. Setup Term
        $term = Terms::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);

        // 2. Setup Department
        $dept = Departments::create([
            'department_name' => 'College of Information Technology',
            'department_code' => 'CIT',
        ]);

        // 3. Setup Course
        $course = Course::create([
            'course_code' => 'IT102',
            'course_name' => 'Programming 1',
            'lecture_hours' => 2,
            'lab_hours' => 3,
            'units' => 3,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        // 4. Setup Section
        $section = Sections::create([
            'section_name' => 'IT 1A',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        $sectionB = Sections::create([
            'section_name' => 'IT 1B',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $dept->id,
            'term_id' => $term->id,
            'status' => 'active',
        ]);

        // 5. Setup Rooms (IT101 and IT102)
        $room1 = Rooms::create([
            'room_code' => 'IT101',
            'room_name' => 'Room 101',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $dept->id,
        ]);

        $room2 = Rooms::create([
            'room_code' => 'IT102',
            'room_name' => 'Room 102',
            'room_type' => 'lecture',
            'status' => 'available',
            'department_id' => $dept->id,
        ]);

        // Scenario 1: Both rooms are free on Monday at 07:00.
        // We request a recommendation for Monday at 07:00.
        // The top recommendation should be on Monday at 07:00.
        $payload = [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $dept->id,
            'duration_slots' => 4, // 2 hours
            'room_id' => $room1->id,
            'mode' => 'on-site',
            'preferred_day' => 'Monday',
            'preferred_start_time' => '07:00',
        ];

        $response = $this->postJson('/api/schedule-recommendations/recommend-split', $payload);
        $response->assertStatus(200);

        $recs = $response->json('recommendations');
        $this->assertNotEmpty($recs);

        // Since Monday 07:00 is free, the top recommendation should be Monday at 07:00.
        $this->assertEquals('Monday', $recs[0]['day']);
        $this->assertEquals('07:00', $recs[0]['start_time']);

        // Scenario 2: Room 1 is occupied on Monday 07:00–09:00.
        // But Room 2 is free.
        // Requesting for Room 1 on Monday at 07:00 should recommend Room 2 on Monday at 07:00.
        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $sectionB->id,
            'course_id' => $course->id,
            'room_id' => $room1->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'draft',
            'department_id' => $dept->id,
        ]);

        $response2 = $this->postJson('/api/schedule-recommendations/recommend-split', $payload);
        $response2->assertStatus(200);
        $recs2 = $response2->json('recommendations');
        $this->assertNotEmpty($recs2);

        // Should recommend Room 2 on Monday at 07:00.
        $this->assertEquals('Monday', $recs2[0]['day']);
        $this->assertEquals('07:00', $recs2[0]['start_time']);
        $this->assertEquals($room2->id, $recs2[0]['room_id']);

        // Scenario 3: BOTH Room 1 and Room 2 are occupied on Monday 07:00–09:00.
        // But they are free on Tuesday 07:00–09:00.
        // It should recommend Tuesday at 07:00, and NEVER change the time.
        Schedule::create([
            'term_id' => $term->id,
            'section_id' => $sectionB->id,
            'course_id' => $course->id,
            'room_id' => $room2->id,
            'day' => 'Monday',
            'start_time' => '07:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'draft',
            'department_id' => $dept->id,
        ]);

        $response3 = $this->postJson('/api/schedule-recommendations/recommend-split', $payload);
        $response3->assertStatus(200);
        $recs3 = $response3->json('recommendations');
        $this->assertNotEmpty($recs3);

        // Should recommend another day, but still keeping 07:00!
        $this->assertNotEquals('Monday', $recs3[0]['day']);
        $this->assertEquals('07:00', $recs3[0]['start_time']);
    }

    public function test_laboratory_recommendation_returns_room_tba_when_no_lab_room_is_available()
    {
        $user = \App\Models\User::create([
            'name' => 'TBA User', 'username' => 'tba-user', 'email' => 'tba@example.com',
            'password' => bcrypt('password'), 'role' => 'secretary',
        ]);
        $this->actingAs($user);

        $term = Terms::create([
            'academic_year' => '2026-2027', 'semester' => '1st',
            'is_active' => true, 'is_enabled' => true,
        ]);
        $dept = Departments::create([
            'department_name' => 'College of Information Technology', 'department_code' => 'CIT',
        ]);
        $course = Course::create([
            'course_code' => 'ITL201', 'course_name' => 'Laboratory Practice',
            'lecture_hours' => 0, 'lab_hours' => 3, 'units' => 2,
            'course_category' => 'major', 'room_type_required' => 'laboratory',
            'year_level' => '1', 'semester' => '1st', 'department_id' => $dept->id,
            'status' => 'active',
        ]);
        $section = Sections::create([
            'section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st',
            'department_id' => $dept->id, 'term_id' => $term->id, 'status' => 'active',
        ]);

        $response = $this->postJson('/api/schedule-recommendations/recommend-split', [
            'term_id' => $term->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'department_id' => $dept->id,
            'duration_slots' => 18,
            'mode' => 'on-site',
            'meeting_type' => 'laboratory',
            'preferred_day' => 'Monday',
            'preferred_start_time' => '07:00',
        ]);

        $response->assertOk()->assertJsonPath('recommendations.0.room_id', null);
        $this->assertSame('Room TBA', $response->json('recommendations.0.room_name'));
    }
}
