<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\User;
use App\Support\ActorIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemNotificationReadIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_and_bulk_reads_preserve_user_system_and_legacy_actor_evidence(): void
    {
        $department = Departments::create(['department_code' => 'READ', 'department_name' => 'Read checks']);
        $user = User::factory()->create(['role' => 'dean', 'department_id' => $department->id]);
        $evidence = [
            [$user->id, ['_actor' => ActorIdentity::snapshot($user), '_actor_type' => 'user']],
            [null, ['_actor' => null, '_actor_type' => 'system']],
            [null, ['_actor' => null, '_actor_type' => 'unknown']],
            [null, ['legacy' => true]],
            [null, null],
        ];

        $this->actingAs($user);
        $notices = [];
        foreach ($evidence as [$actorId, $metadata]) {
            // Direct inserts also cover legacy rows without insertion-time model snapshots.
            foreach (['single', 'bulk'] as $operation) {
                $row = ['user_id' => $user->id, 'actor_id' => $actorId, 'department_id' => $department->id,
                    'type' => 'schedule_submitted', 'title' => 'Review', 'message' => 'Ready',
                    'metadata' => $metadata === null ? null : json_encode($metadata), 'created_at' => now(), 'updated_at' => now()];
                $id = DB::table('system_notifications')->insertGetId($row);
                $notices[$id] = DB::table('system_notifications')->find($id);
                if ($operation === 'single') {
                    $this->patchJson("/api/notifications/{$id}/read")->assertOk();
                    $this->assertNotNull(DB::table('system_notifications')->find($id)->read_at);
                }
            }
        }

        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', count($evidence));
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('unread_count', 0);
        foreach ($notices as $id => $before) {
            $after = DB::table('system_notifications')->find($id);
            $this->assertNotNull($after->read_at);
            $this->assertSame($before->actor_id, $after->actor_id);
            $this->assertSame($before->metadata, $after->metadata);
        }
    }

    public function test_read_guard_still_rejects_actor_replacement_and_allows_unrelated_metadata(): void
    {
        $user = User::factory()->create(['role' => 'vpaa']);
        $metadata = ['_actor' => ActorIdentity::snapshot($user), '_actor_type' => 'user'];
        $id = DB::table('system_notifications')->insertGetId(['user_id' => $user->id, 'actor_id' => $user->id,
            'type' => 'schedule_submitted', 'title' => 'Review', 'message' => 'Ready', 'metadata' => json_encode($metadata)]);
        foreach ([
            ['actor_id' => null],
            ['metadata' => json_encode([...$metadata, '_actor' => null])],
            ['metadata' => json_encode([...$metadata, '_actor' => [...$metadata['_actor'], 'name' => 'Replacement']])],
            ['metadata' => json_encode([...$metadata, '_actor_type' => 'User'])],
            ['metadata' => json_encode(['_actor' => $metadata['_actor']])],
        ] as $replacement) {
            try {
                DB::table('system_notifications')->where('id', $id)->update($replacement);
                $this->fail('The database allowed recorded notification identity to change.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Historical identity evidence cannot be replaced', $exception->getMessage());
            }
        }
        DB::table('system_notifications')->where('id', $id)->update(['metadata' => json_encode([...$metadata, 'unrelated' => true])]);
        $after = DB::table('system_notifications')->find($id);
        $this->assertSame($user->id, $after->actor_id);
        $this->assertSame([...$metadata, 'unrelated' => true], json_decode($after->metadata, true));

        $legacyId = DB::table('system_notifications')->insertGetId(['user_id' => $user->id,
            'type' => 'schedule_submitted', 'title' => 'Legacy', 'message' => 'Ready', 'metadata' => '{}']);
        $this->expectException(QueryException::class);
        DB::table('system_notifications')->where('id', $legacyId)->update(['metadata' => json_encode(['_actor' => null])]);
    }
}
