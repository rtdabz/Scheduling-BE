<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\Program;
use App\Models\RoomRecoveryCase;
use App\Models\Rooms;
use App\Models\ScheduleGenerationRun;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowOriginIdentityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['dean', 'secretary', 'director', 'program_head', 'vpaa']);
    }

    private function fixture(string $role = 'secretary'): array
    {
        $department = Departments::create(['department_name' => 'Integrity College', 'department_code' => 'INT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'INT', 'name' => 'Integrity Program']);
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $room = Rooms::create(['room_code' => 'INT-1', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $actor = User::factory()->create(['name' => 'Original Actor', 'role' => $role, 'department_id' => $department->id,
            'program_id' => $role === 'program_head' ? $program->id : null]);
        $run = ScheduleGenerationRun::create(['run_id' => (string) Str::uuid(), 'requested_by' => $actor->id,
            'semester_id' => $semester->id, 'department_id' => $department->id, 'year_level' => 1, 'status' => 'queued']);
        $case = RoomRecoveryCase::create(['room_id' => $room->id, 'semester_id' => $semester->id,
            'department_id' => $department->id, 'trigger' => 'room_closed', 'reason' => 'Repairs', 'original_rows' => [], 'created_by' => $actor->id]);

        return [$actor, $run, $case];
    }

    #[DataProvider('roles')]
    public function test_origin_identities_survive_rename_role_change_archive_and_replacement(string $role): void
    {
        [$actor, $run, $case] = $this->fixture($role);
        $actor->update(['name' => 'Changed Name', 'role' => 'secretary', 'is_active' => false]);
        $actor->delete();
        $replacement = User::factory()->create(['name' => 'Replacement Official', 'role' => $role,
            'department_id' => $actor->department_id, 'program_id' => $actor->program_id]);
        $run->update(['status' => 'completed', 'result' => ['schedules' => []], 'finished_at' => now()]);

        foreach ([[$run, 'requested_by'], [$case, 'created_by']] as [$record, $column]) {
            $saved = $record->fresh();
            $this->assertSame($actor->id, $saved->{$column});
            $this->assertSame('Original Actor', $saved->actor_snapshots[$column]['name']);
            $this->assertSame($role, $saved->actor_snapshots[$column]['role']);
            $this->assertSame($actor->program_id, $saved->actor_snapshots[$column]['program_id']);
            $this->assertNotSame($replacement->id, $saved->{$column});
        }
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_origin_ids_and_snapshots_cannot_be_replaced_through_models_or_sql(): void
    {
        [$actor, $run, $case] = $this->fixture();
        $other = User::factory()->create(['role' => 'dean', 'department_id' => $actor->department_id]);
        foreach ([[$run, 'requested_by'], [$case, 'created_by']] as [$record, $column]) {
            try {
                $record->update([$column => $other->id]);
                $this->fail('The model allowed the origin actor to be replaced.');
            } catch (LogicException) {
                $this->assertSame($actor->id, $record->fresh()->{$column});
            }
            foreach ([[$column => $other->id], ['actor_snapshots' => json_encode([$column => ['id' => $other->id, 'name' => 'Forged actor']])]] as $changes) {
                try {
                    DB::table($record->getTable())->where('id', $record->id)->update($changes);
                    $this->fail('The database allowed the origin identity to be replaced.');
                } catch (QueryException) {
                    $this->assertSame($actor->id, $record->fresh()->{$column});
                    $this->assertSame('Original Actor', $record->fresh()->actor_snapshots[$column]['name']);
                }
            }
        }
    }

    public function test_legacy_run_progress_does_not_invent_a_requester_snapshot(): void
    {
        [$actor, $run] = $this->fixture();
        $legacy = $run->getRawOriginal();
        unset($legacy['id'], $legacy['actor_snapshots']);
        $legacy['run_id'] = (string) Str::uuid();
        $id = DB::table('schedule_generation_runs')->insertGetId($legacy);
        $actor->update(['name' => 'Renamed Later']);
        ScheduleGenerationRun::findOrFail($id)->update(['status' => 'cancelled', 'finished_at' => now()]);

        $this->assertNull(ScheduleGenerationRun::findOrFail($id)->actor_snapshots);
        try {
            DB::table('schedule_generation_runs')->where('id', $id)->update(['actor_snapshots' => json_encode($run->actor_snapshots)]);
            $this->fail('The database allowed an unrecorded historical identity to be backfilled.');
        } catch (QueryException) {
            $this->assertNull(ScheduleGenerationRun::findOrFail($id)->actor_snapshots);
        }
    }
}
