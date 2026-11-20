<?php

namespace Tests\Feature;

use App\Models\Departments;
use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department logos are base64 blobs. They must leave the API as short cacheable URLs, never inline in
 * list payloads (which once grew /courses and /schedule-history to tens of MB, one copy per row).
 */
class DepartmentLogoDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private function department(string $code = 'CCS'): Departments
    {
        return Departments::create([
            'department_name' => 'Dept '.$code,
            'department_code' => $code,
            'logo' => 'data:image/png;base64,'.self::PNG,
        ]);
    }

    public function test_logo_serializes_as_versioned_url_not_base64(): void
    {
        $department = $this->department();

        $json = $department->toArray();

        $this->assertStringContainsString('/api/departments/'.$department->id.'/logo?v=', $json['logo']);
        $this->assertStringNotContainsString('base64', json_encode($json));
        $this->assertSame('data:image/png;base64,'.self::PNG, $department->rawLogo());
    }

    public function test_logo_endpoint_serves_image_publicly_with_long_cache(): void
    {
        $department = $this->department();
        $version = Departments::logoVersion($department->rawLogo());

        $response = $this->get("/api/departments/{$department->id}/logo?v={$version}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG), $response->getContent());
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));

        $this->get("/api/departments/{$department->id}/logo", ['If-None-Match' => '"'.$version.'"'])->assertStatus(304);
    }

    /**
     * Regression: a plain <img> (no Origin header) cached the logo without CORS headers, and the browser then
     * reused that copy for the crossOrigin load PDF printing makes, which failed and dropped the logo.
     */
    public function test_logo_is_cors_readable_whether_or_not_the_request_had_an_origin(): void
    {
        $department = $this->department();
        $url = parse_url($department->logo, PHP_URL_PATH).'?'.parse_url($department->logo, PHP_URL_QUERY);

        $this->get($url)->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $withOrigin = $this->get($url, ['Origin' => 'http://localhost:5173'])->assertOk();
        $this->assertContains($withOrigin->headers->get('Access-Control-Allow-Origin'), ['*', 'http://localhost:5173']);
    }

    public function test_missing_logo_returns_404(): void
    {
        $department = Departments::create(['department_name' => 'No Logo', 'department_code' => 'NL']);

        $this->assertNull($department->logo);
        $this->get("/api/departments/{$department->id}/logo")->assertNotFound();
        $this->get('/api/departments/999999/logo')->assertNotFound();
    }

    public function test_updating_with_unchanged_logo_url_keeps_logo(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = $this->department();

        $this->actingAs($vpaa, 'sanctum')
            ->patchJson("/api/departments/{$department->id}", [
                'department_name' => 'Renamed',
                'department_code' => 'CCS',
                'logo' => $department->logo,
            ])
            ->assertOk()
            ->assertJsonPath('department_name', 'Renamed');

        $this->assertSame('data:image/png;base64,'.self::PNG, $department->fresh()->rawLogo());
    }

    public function test_updating_with_null_logo_still_removes_it(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = $this->department();

        $this->actingAs($vpaa, 'sanctum')
            ->patchJson("/api/departments/{$department->id}", ['logo' => null])
            ->assertOk();

        $this->assertNull($department->fresh()->rawLogo());
    }

    public function test_list_endpoints_do_not_embed_base64_logos(): void
    {
        $vpaa = User::factory()->create(['role' => 'vpaa']);
        $department = $this->department();
        $version = ScheduleHistoryVersion::forceCreate([
            'source' => 'semester_change', 'action' => 'schedule_semester_archived',
            'actor_user_id' => $vpaa->id, 'department_id' => $department->id,
        ]);
        ScheduleHistoryItem::create([
            'history_version_id' => $version->id, 'original_schedule_id' => 1, 'after_snapshot' => ['day' => 'Monday'],
            'snapshot_metadata' => ['department_code' => 'CCS', 'department_logo' => 'data:image/png;base64,'.self::PNG],
        ]);

        foreach (['/api/courses', '/api/departments', '/api/rooms', '/api/faculties', '/api/schedule-history'] as $uri) {
            $body = $this->actingAs($vpaa, 'sanctum')->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('base64,', $body, "{$uri} embeds a base64 image");
        }

        // The stored (append-only) row keeps its legacy copy; the API resolves the URL by department code.
        $detail = $this->actingAs($vpaa, 'sanctum')->getJson('/api/schedule-history/'.$version->id)->assertOk();
        $this->assertStringNotContainsString('base64,', $detail->getContent());
        $detail
            ->assertJsonPath('data.snapshots.0.department_logo', $department->logo)
            ->assertJsonPath('data.snapshots.0.department_code', 'CCS');
    }
}
