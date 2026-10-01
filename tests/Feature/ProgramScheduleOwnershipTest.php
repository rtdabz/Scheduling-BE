<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use App\Services\Scheduling\Schedule\ScheduleAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inside a department, each program's timetable is written by its owner: the
 * program's active Program Head, or the Secretary when the program has none.
 * The Dean and the VPAA review and approve but write nothing. Everyone keeps
 * reading the whole department.
 */
class ProgramScheduleOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_secretary_cannot_change_a_program_that_has_a_program_head(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->putJson("/api/schedules/{$f['rowA']->id}", ['start_time' => '10:00', 'end_time' => '11:00'])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);

        $this->actingAs($f['secretary'])
            ->deleteJson("/api/schedules/{$f['rowA']->id}")
            ->assertForbidden();

        // A generated timetable replacing the Program Head's section.
        $this->actingAs($f['secretary'])
            ->postJson('/api/schedules/batch', [
                'operations' => [[
                    'semester_id' => $f['semester']->id,
                    'section_id' => $f['sectionA']->id,
                    'course_id' => $f['course']->id,
                    'room_id' => $f['room']->id,
                    'department_id' => $f['department']->id,
                    'day' => 'Wednesday',
                    'start_time' => '13:00',
                    'end_time' => '14:00',
                    'mode' => 'on-site',
                    'status' => 'draft',
                ]],
                'replace_section_ids' => [$f['sectionA']->id],
                'replace_semester_id' => $f['semester']->id,
            ])
            ->assertForbidden();

        $this->assertSame('08:00', substr((string) $f['rowA']->refresh()->start_time, 0, 5));
        $this->assertSame(1, Schedule::query()->where('section_id', $f['sectionA']->id)->count());
    }

    public function test_secretary_writes_a_program_without_a_program_head(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->deleteJson("/api/schedules/{$f['rowB']->id}")
            ->assertOk();

        $this->assertNull(Schedule::query()->find($f['rowB']->id));
    }

    public function test_authority_follows_the_program_head_slot(): void
    {
        $f = $this->fixture();
        $tryDelete = fn () => $this->actingAs($f['secretary'])->deleteJson("/api/schedules/{$f['rowA']->id}");

        // Program Head deactivated: the Secretary takes the program back.
        $f['headA']->update(['is_active' => false]);
        $this->assertContains($f['programA']->id, $this->ownedBy($f['secretary']));

        // A new Program Head is assigned: the Secretary loses it again.
        $this->grantCapabilities(User::factory()->create([
            'role' => 'program_head',
            'department_id' => $f['department']->id,
            'program_id' => $f['programA']->id,
        ]));
        $this->assertNotContains($f['programA']->id, $this->ownedBy($f['secretary']));
        $tryDelete()->assertForbidden();

        User::query()->where('role', 'program_head')->update(['is_active' => false]);
        $tryDelete()->assertOk();
    }

    public function test_program_head_writes_only_their_own_program(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['headA'])
            ->putJson("/api/schedules/{$f['rowA']->id}", ['start_time' => '10:00', 'end_time' => '11:00'])
            ->assertOk();

        $this->actingAs($f['headA'])
            ->deleteJson("/api/schedules/{$f['rowB']->id}")
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);

        // Moving its own row into a sibling program's section is a write there.
        $this->actingAs($f['headA'])
            ->putJson("/api/schedules/{$f['rowA']->id}", ['section_id' => $f['sectionB']->id])
            ->assertForbidden();

        $this->assertSame($f['sectionA']->id, $f['rowA']->refresh()->section_id);
        $this->assertNotNull(Schedule::query()->find($f['rowB']->id));
    }

    public function test_dean_and_vpaa_write_no_schedule(): void
    {
        $f = $this->fixture();
        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $f['department']->id]);
        $vpaa = User::factory()->create(['role' => 'vpaa', 'department_id' => null]);

        foreach ([$dean, $vpaa] as $reviewer) {
            $this->actingAs($reviewer)
                ->deleteJson("/api/schedules/{$f['rowB']->id}")
                ->assertForbidden();
            $this->actingAs($reviewer)
                ->patchJson('/api/schedules/batch-status', ['ids' => [$f['rowB']->id], 'status' => 'completed'])
                ->assertForbidden();
            $this->assertSame([], $this->ownedBy($reviewer));
        }

        $this->assertSame('draft', $f['rowB']->refresh()->status);

        // Not merely owning nothing: the VPAA role holds no authoring grant.
        $this->assertSame([], array_values(array_intersect(
            ['schedule.create', 'schedule.update', 'schedule.delete', 'schedule.generate', 'schedule.submit', 'schedule.withdraw'],
            config('capabilities.role_defaults.vpaa'),
        )));
    }

    public function test_generation_is_limited_to_owned_sections(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->postJson('/api/schedule-recommendations/available-slots', [
                'section_id' => $f['sectionA']->id,
                'course_id' => $f['course']->id,
                'duration_slots' => 2,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);

        $this->actingAs($f['headA'])
            ->postJson('/api/schedule-recommendations/available-slots', [
                'section_id' => $f['sectionB']->id,
                'course_id' => $f['course']->id,
                'duration_slots' => 2,
            ])
            ->assertForbidden();
    }

    public function test_conflict_fixes_follow_ownership(): void
    {
        $f = $this->fixture();
        [$low, $high] = [min($f['rowA']->id, $f['rowB']->id), max($f['rowA']->id, $f['rowB']->id)];

        $this->actingAs($f['headA'])
            ->postJson("/api/conflicts/room_conflict:{$low}:{$high}/resolve", [
                'action' => 'change_room',
                'schedule_id' => $f['rowB']->id,
                'room_id' => $f['room']->id,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);
    }

    public function test_submit_and_withdraw_follow_ownership(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['secretary'])
            ->postJson("/api/departments/{$f['department']->id}/submit-schedules", ['section_ids' => [$f['sectionA']->id]])
            ->assertForbidden()
            ->assertJsonPath('message', ScheduleAuthorizationService::PROGRAM_FORBIDDEN_MESSAGE);

        $this->actingAs($f['secretary'])
            ->postJson("/api/departments/{$f['department']->id}/withdraw-submission", ['section_ids' => [$f['sectionA']->id]])
            ->assertForbidden();

        $this->actingAs($f['headA'])
            ->postJson("/api/departments/{$f['department']->id}/withdraw-submission", ['section_ids' => [$f['sectionB']->id]])
            ->assertForbidden();
    }

    public function test_reads_stay_department_wide_and_ownership_is_exposed(): void
    {
        $f = $this->fixture();

        foreach ([$f['secretary'], $f['headA']] as $user) {
            $ids = collect($this->actingAs($user)->getJson("/api/schedules/semester/{$f['semester']->id}")->assertOk()->json())
                ->flatten(1)
                ->pluck('id')
                ->filter()
                ->all();
            $this->assertContains($f['rowA']->id, $ids);
            $this->assertContains($f['rowB']->id, $ids);
        }

        $this->actingAs($f['secretary'])->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('can_edit_program_ids', [$f['programB']->id]);
        $this->actingAs($f['headA'])->getJson('/api/initial-data?include=departments')
            ->assertOk()
            ->assertJsonPath('can_edit_program_ids', [$f['programA']->id]);
    }

    public function test_program_head_sections_page_is_limited_to_their_program(): void
    {
        $f = $this->fixture();

        $ids = collect($this->actingAs($f['headA'])->getJson('/api/sections')->assertOk()->json())->pluck('id')->all();
        $this->assertSame([$f['sectionA']->id], $ids);

        $this->actingAs($f['headA'])->getJson("/api/sections/{$f['sectionB']->id}")->assertNotFound();
        $this->actingAs($f['headA'])->putJson("/api/sections/{$f['sectionB']->id}", ['section_name' => 'ENG1Z'])->assertForbidden();
        $this->actingAs($f['headA'])->deleteJson("/api/sections/{$f['sectionB']->id}")->assertForbidden();
        $this->actingAs($f['headA'])->postJson('/api/sections', [
            'section_name' => 'ENG1B',
            'year_level' => '1',
            'department_id' => $f['department']->id,
            'program_id' => $f['programB']->id,
        ])->assertForbidden();
        $this->assertSame('ENG1A', $f['sectionB']->refresh()->section_name);

        // The Secretary keeps the whole department.
        $secretaryIds = collect($this->actingAs($f['secretary'])->getJson('/api/sections')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$f['sectionA']->id, $f['sectionB']->id], $secretaryIds);
    }

    public function test_program_head_sees_own_and_shared_rooms_and_sibling_meetings_only_in_shared_rooms(): void
    {
        $f = $this->fixture();
        $homeA = Rooms::create(['room_code' => 'CAS201', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        $homeB = Rooms::create(['room_code' => 'CAS202', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $f['department']->id]);
        // home_program_id is not mass assignable.
        $homeA->forceFill(['home_program_id' => $f['programA']->id])->save();
        $homeB->forceFill(['home_program_id' => $f['programB']->id])->save();
        // rowB (program B) already sits in the shared room CAS101.
        $rowBHome = Schedule::create([
            ...collect($f['rowB']->getAttributes())->only(['semester_id', 'section_id', 'course_id', 'department_id', 'program_id', 'mode', 'status'])->all(),
            'room_id' => $homeB->id,
            'day' => 'Thursday',
            'start_time' => '08:00',
            'end_time' => '09:00',
        ]);

        $roomIds = collect($this->actingAs($f['headA'])->getJson('/api/rooms')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$f['room']->id, $homeA->id], $roomIds);
        $this->actingAs($f['headA'])->getJson("/api/rooms/{$homeB->id}")->assertNotFound();

        $initial = $this->actingAs($f['headA'])->getJson('/api/initial-data?include=rooms,schedules')->assertOk();
        $this->assertEqualsCanonicalizing([$f['room']->id, $homeA->id], collect($initial->json('rooms'))->pluck('id')->all());
        $scheduleIds = collect($initial->json('schedules'))->pluck('id')->all();
        $this->assertContains($f['rowA']->id, $scheduleIds);
        $this->assertContains($f['rowB']->id, $scheduleIds);
        $this->assertNotContains($rowBHome->id, $scheduleIds);
        // rowB shows on the room view only: its section is not offered to the timetable grid.
        $sectionIds = collect($this->actingAs($f['headA'])->getJson('/api/initial-data?include=sections')->json('sections'))->pluck('id')->all();
        $this->assertSame([$f['sectionA']->id], $sectionIds);

        $listed = collect($this->actingAs($f['headA'])->getJson("/api/schedules/semester/{$f['semester']->id}")->assertOk()->json())->pluck('id')->all();
        $this->assertContains($f['rowB']->id, $listed);
        $this->assertNotContains($rowBHome->id, $listed);

        // The Secretary keeps the whole department.
        $this->assertContains($homeB->id, collect($this->actingAs($f['secretary'])->getJson('/api/rooms')->json())->pluck('id')->all());
        $this->assertContains($rowBHome->id, collect($this->actingAs($f['secretary'])->getJson('/api/initial-data?include=schedules')->json('schedules'))->pluck('id')->all());
    }

    public function test_program_head_sees_and_uses_only_its_own_and_department_wide_curricula(): void
    {
        $f = $this->fixture();
        $curriculum = fn (?Program $program, string $code): Curriculum => Curriculum::create([
            'name' => $code,
            'code' => $code,
            'department_id' => $f['department']->id,
            'program_id' => $program?->id,
            'effective_school_year' => '2026-2027',
            'status' => 'active',
        ]);
        $own = $curriculum($f['programA'], 'PSY-2026');
        $sibling = $curriculum($f['programB'], 'ENG-2026');
        $shared = $curriculum(null, 'CAS-2026');

        $ids = collect($this->actingAs($f['headA'])->getJson('/api/curriculum')->assertOk()->json())->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$own->id, $shared->id], $ids);
        $this->actingAs($f['headA'])->getJson("/api/curriculum/{$sibling->id}")->assertForbidden();
        $this->actingAs($f['headA'])->getJson("/api/curriculum/{$sibling->id}/full")->assertForbidden();
        $this->actingAs($f['headA'])->getJson("/api/courses?curriculum_id={$sibling->id}")->assertForbidden();
        $this->actingAs($f['headA'])->getJson("/api/curriculum/{$own->id}/full")->assertOk();

        // Using it: a sibling program's curriculum cannot be given to the Program Head's section.
        $this->actingAs($f['headA'])
            ->putJson("/api/sections/{$f['sectionA']->id}", ['curriculum_id' => $sibling->id])
            ->assertStatus(422);
        $this->assertNotSame($sibling->id, $f['sectionA']->refresh()->curriculum_id);

        $secretaryIds = collect($this->actingAs($f['secretary'])->getJson('/api/curriculum')->json())->pluck('id')->all();
        $this->assertContains($sibling->id, $secretaryIds);
    }

    /** @return list<int> */
    private function ownedBy(User $user): array
    {
        return app(ScheduleAuthorizationService::class)->writableProgramIdsFor($user->fresh());
    }

    private function fixture(): array
    {
        $department = Departments::create(['department_name' => 'College of Arts and Sciences', 'department_code' => 'CAS']);
        $programA = Program::create(['department_id' => $department->id, 'code' => 'BAPSY', 'name' => 'Psychology']);
        $programB = Program::create(['department_id' => $department->id, 'code' => 'BAENG', 'name' => 'English']);
        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'is_active' => true,
            'is_enabled' => true,
        ]);
        $room = Rooms::create(['room_code' => 'CAS101', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $course = Course::create([
            'course_code' => 'GE101',
            'course_name' => 'Understanding the Self',
            'lecture_hours' => 1,
            'lab_hours' => 0,
            'units' => 1,
            'course_category' => 'major',
            'room_type_required' => 'lecture',
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'status' => 'active',
        ]);
        $section = fn (Program $program, string $name): Sections => Sections::create([
            'section_name' => $name,
            'year_level' => '1',
            'semester' => '1st',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'semester_id' => $semester->id,
            'status' => 'active',
        ]);
        $sectionA = $section($programA, 'PSY1A');
        $sectionB = $section($programB, 'ENG1A');
        $row = fn (Sections $section, string $day): Schedule => Schedule::create([
            'semester_id' => $semester->id,
            'section_id' => $section->id,
            'course_id' => $course->id,
            'room_id' => $room->id,
            'department_id' => $department->id,
            'program_id' => $section->program_id,
            'day' => $day,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'mode' => 'on-site',
            'status' => 'draft',
        ]);

        return [
            'department' => $department,
            'programA' => $programA,
            'programB' => $programB,
            'semester' => $semester,
            'room' => $room,
            'course' => $course,
            'sectionA' => $sectionA,
            'sectionB' => $sectionB,
            'rowA' => $row($sectionA, 'Monday'),
            'rowB' => $row($sectionB, 'Tuesday'),
            'secretary' => $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id])),
            'headA' => $this->grantCapabilities(User::factory()->create([
                'role' => 'program_head',
                'department_id' => $department->id,
                'program_id' => $programA->id,
            ])),
        ];
    }
}
