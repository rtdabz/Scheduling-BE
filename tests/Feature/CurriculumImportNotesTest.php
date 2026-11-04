<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumImport;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumImportNotesTest extends TestCase
{
    use RefreshDatabase;

    private const MATH_PREP = 'Note: Math prep(Pre-Calculus for NON-STEM(Algebra & Trigo) for GAS, HUMSS, ABM to be taken only';

    private Departments $department;

    private Program $program;

    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Departments::create(['department_name' => 'Computer Studies', 'department_code' => 'CCS']);
        $this->program = Program::create(['department_id' => $this->department->id, 'code' => 'BSIT', 'name' => 'Information Technology']);
        $this->secretary = User::factory()->create(['role' => 'secretary', 'department_id' => $this->department->id]);
        $this->secretary->assignRole('secretary');
        $this->secretary = $this->secretary->fresh();

        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', [
            'expected_revision' => (int) $this->department->fresh()->curriculum_import_settings_revision,
            'lecture_minutes_per_unit' => 60,
            'laboratory_minutes_per_unit' => 180,
            'rules' => collect(['IT' => 'major', 'PRAC' => 'major', 'GEC' => 'minor', 'GEE' => 'minor', 'PATH' => 'minor', 'ROTC' => 'minor'])
                ->map(fn (string $category, string $prefix): array => ['match_kind' => 'prefix', 'match_value' => $prefix, 'category' => $category])
                ->values()->all(),
            'acknowledge_overlaps' => false,
        ])->assertOk();
    }

    /** The reviewed TCC draft, whose notes are what the browser extractor reads from the real PDF. */
    private function payload(?array $notes = null): array
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        $payload['metadata']['program_id'] = $this->program->id;
        foreach ($payload['rows'] as &$row) {
            $row['code'] = ['I1 125' => 'IT 125', 'PATH FITt 2' => 'PATH FIT 2', 'PATH FiIT3' => 'PATH FIT 3'][$row['code']] ?? $row['code'];
            if ($row['code'] === 'Prac 101') {
                $row['scheduling_basis'] = 'total_hours';
                $row['total_hours'] = 486;
            }
        }
        unset($row);
        if ($notes !== null) {
            $payload['notes'] = $notes;
        }

        return $payload;
    }

    private function mathPrep(array $overrides = []): array
    {
        return array_replace([
            'id' => 'n1',
            'source_text' => '_______'.self::MATH_PREP,
            'text' => self::MATH_PREP,
            'runs' => [['length' => mb_strlen(self::MATH_PREP), 'color' => '#c00000']],
            'scope' => ['level' => 'year', 'year_level' => 1],
            'provenance' => ['kind' => 'pdf', 'page' => 1, 'bbox' => [23.28, 304.44, 295.28, 310.8]],
            'flags' => [],
        ], $overrides);
    }

    private function validateDraft(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $payload)->assertOk();
    }

    private function withoutErrors(array $payload): array
    {
        $issues = collect($this->validateDraft($payload)->json('issues'));
        $this->assertSame([], $issues->where('severity', 'error')->pluck('message')->all());

        return $payload;
    }

    private function commit(array $payload, string $key = 'notes-import-0001'): \Illuminate\Testing\TestResponse
    {
        $validation = $this->validateDraft($payload);
        $this->assertTrue($validation->json('valid'), collect($validation->json('issues'))->pluck('message')->implode("\n"));

        return $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload + [
            'request_key' => $key,
            'fingerprint' => $validation->json('fingerprint'),
            'expected_settings_revision' => $validation->json('settings_revision'),
            'confirmed' => true,
        ]);
    }

    public function test_the_red_math_prep_note_is_saved_on_the_curriculum_and_returned_by_its_detail_apis(): void
    {
        $extracted = $this->payload()['notes'];
        $this->assertSame(self::MATH_PREP, $extracted[0]['text']);
        $this->assertSame('#C00000', $extracted[0]['runs'][0]['color']);

        $payload = $this->withoutErrors($this->payload());
        $this->validateDraft($payload)
            ->assertJsonPath('notes.0.text', self::MATH_PREP)
            ->assertJsonPath('notes.0.runs.0.color', '#C00000')
            ->assertJsonPath('notes.0.scope', ['level' => 'year', 'year_level' => 1]);

        $id = $this->commit($payload)->assertCreated()->assertJsonPath('curriculum.notes.0.text', self::MATH_PREP)->json('curriculum.id');

        $stored = Curriculum::findOrFail($id)->notes;
        $this->assertSame([[
            'id' => 'n1',
            'text' => self::MATH_PREP,
            'source_text' => '_______'.self::MATH_PREP,
            'runs' => [['length' => mb_strlen(self::MATH_PREP), 'color' => '#C00000']],
            'scope' => ['level' => 'year', 'year_level' => 1],
            'provenance' => $extracted[0]['provenance'],
            'flags' => [],
        ]], $stored);
        // The note is content only: all 59 courses are imported unchanged and no course was made from it.
        $this->assertSame(59, Course::count());
        $this->assertSame(59, Curriculum::findOrFail($id)->courses()->count());
        $this->assertFalse(Course::where('course_name', 'like', '%Math prep%')->exists());

        $this->actingAs($this->secretary)->getJson("/api/curriculum/{$id}")->assertOk()->assertJsonPath('notes.0.runs.0.color', '#C00000');
        $this->actingAs($this->secretary)->getJson("/api/curriculum/{$id}/full")->assertOk()->assertJsonPath('curriculum.notes.0.text', self::MATH_PREP);
        $this->assertSame(self::MATH_PREP, CurriculumImport::firstOrFail()->record['notes'][0]['text']);
    }

    public function test_multiline_mixed_colour_and_course_scoped_notes_keep_their_text_and_spans(): void
    {
        $multiline = "Notes:\n1. Practicum requires 486 hours.\n2. Electives follow the approved list.";
        $mixed = 'Reminder: GEC 1 is required.';
        $notes = [
            $this->mathPrep(),
            ['id' => 'n2', 'source_text' => $multiline, 'text' => $multiline, 'runs' => [['length' => 7, 'color' => '#000000'], ['length' => mb_strlen($multiline) - 7, 'color' => '#1F4E79']], 'scope' => ['level' => 'curriculum'], 'provenance' => ['kind' => 'pdf', 'page' => 1, 'bbox' => [20, 830, 300, 860]], 'flags' => []],
            ['id' => 'n3', 'source_text' => $mixed, 'text' => $mixed, 'runs' => [['length' => 10, 'color' => '#C00000'], ['length' => mb_strlen($mixed) - 10, 'color' => null]], 'scope' => ['level' => 'course', 'row_id' => 'r4'], 'provenance' => null, 'flags' => []],
        ];
        $id = $this->commit($this->withoutErrors($this->payload($notes)))->assertCreated()->json('curriculum.id');

        $saved = Curriculum::findOrFail($id)->notes;
        $this->assertSame(['n1', 'n2', 'n3'], array_column($saved, 'id'));
        $this->assertSame($multiline, $saved[1]['text']);
        $this->assertSame([['length' => 7, 'color' => '#000000'], ['length' => mb_strlen($multiline) - 7, 'color' => '#1F4E79']], $saved[1]['runs']);
        // A course-scoped note now names the course the draft row became.
        $courseId = CurriculumImport::firstOrFail()->summary['rows'][3]['course_id'];
        $this->assertSame(['level' => 'course', 'course_id' => $courseId], $saved[2]['scope']);
    }

    public function test_inconsistent_or_unsafe_notes_are_rejected_and_nothing_is_imported(): void
    {
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $this->payload([$this->mathPrep(['runs' => [['length' => 98, 'color' => 'red']]])]))
            ->assertUnprocessable()->assertJsonValidationErrors('notes.0.runs.0.color');
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $this->payload([$this->mathPrep(['text' => "Note\u{0007} bell", 'source_text' => null, 'runs' => [['length' => 10, 'color' => null]]])]))
            ->assertUnprocessable()->assertJsonValidationErrors('notes.0.text');
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $this->payload([$this->mathPrep(), $this->mathPrep()]))
            ->assertUnprocessable()->assertJsonValidationErrors('notes.0.id');

        $codes = fn (array $note): array => collect($this->validateDraft($this->payload([$note]))->json('issues'))->where('severity', 'error')->pluck('code')->all();
        $this->assertSame(['note_runs_mismatch'], $codes($this->mathPrep(['runs' => [['length' => 5, 'color' => '#C00000']]])));
        // Rewording a source note is caught: only leading form blanks may be dropped for display.
        $this->assertSame(['note_text_mismatch'], $codes($this->mathPrep(['text' => 'Note: Math prep for non-STEM students only.', 'runs' => [['length' => mb_strlen('Note: Math prep for non-STEM students only.'), 'color' => '#C00000']]])));
        $this->assertSame(['note_scope_invalid'], $codes($this->mathPrep(['scope' => ['level' => 'term', 'year_level' => 1]])));
        $this->assertSame(['note_scope_invalid'], $codes($this->mathPrep(['scope' => ['level' => 'course', 'row_id' => 'missing']])));

        // An invalid note blocks the whole import atomically.
        $payload = $this->payload([$this->mathPrep(['runs' => [['length' => 5, 'color' => '#C00000']]])]);
        $validation = $this->validateDraft($payload);
        $this->assertFalse($validation->json('valid'));
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload + ['request_key' => 'notes-import-bad', 'fingerprint' => $validation->json('fingerprint'), 'expected_settings_revision' => $validation->json('settings_revision'), 'confirmed' => true])
            ->assertUnprocessable();
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, Course::count());
    }

    public function test_unknown_colours_are_reported_accurately_and_unread_regions_need_what_the_reviewer_found(): void
    {
        $payload = $this->payload([$this->mathPrep(['runs' => [['length' => mb_strlen(self::MATH_PREP), 'color' => null]], 'flags' => ['color_unknown']])]);
        $payload['source']['unread'] = [
            ['reason' => 'image', 'page' => 1, 'bbox' => [40, 820, 300, 900]],
            ['reason' => 'unrecognized_text', 'page' => 1, 'bbox' => [20, 830, 200, 836], 'text' => 'Prepared by the curriculum committee'],
        ];
        $issues = collect($this->validateDraft($payload)->json('issues'))->keyBy('key');
        // An unknown colour is a fact, worded so it is never mistaken for an exact copy.
        $this->assertSame('notice', $issues['note_color_unknown:n1']['severity']);
        $this->assertStringContainsString('not as an exact copy', $issues['note_color_unknown:n1']['message']);
        // Unread content cannot be confirmed away.
        $this->assertSame(['error', 'error'], [$issues['source_unread:0']['severity'], $issues['source_unread:1']['severity']]);
        $this->assertFalse($this->validateDraft($payload)->json('valid'));

        // Marking a region as typed in needs an actual typed note.
        $payload['source']['unread'][0]['resolution'] = 'transcribed';
        $payload['source']['unread'][1]['resolution'] = 'no_note';
        $issues = collect($this->validateDraft($payload)->json('issues'))->keyBy('key');
        $this->assertSame(['error', 'notice'], [$issues['source_unread:0']['severity'], $issues['source_unread:1']['severity']]);

        $payload['notes'][] = ['id' => 'm1', 'source_text' => null, 'text' => 'Bridging note from the scanned annex.', 'runs' => [['length' => 37, 'color' => null]], 'scope' => ['level' => 'curriculum'], 'provenance' => null, 'flags' => ['manual']];
        $id = $this->commit($this->withoutErrors($payload))->assertCreated()->json('curriculum.id');
        $this->assertNull(Curriculum::findOrFail($id)->notes[0]['runs'][0]['color']);
        $this->assertSame(['manual'], Curriculum::findOrFail($id)->notes[1]['flags']);
        $record = CurriculumImport::firstOrFail();
        $this->assertSame(['transcribed', 'no_note'], array_column($record->record['unread'], 'resolution'));
        $this->assertContains('source_unread:1', array_column($record->summary['notices'], 'key'));
        $this->assertTrue($record->record['source']['hash_declared_by_client']);
    }

    public function test_note_changes_invalidate_the_fingerprint_and_a_replayed_key_must_match(): void
    {
        $payload = $this->withoutErrors($this->payload());
        $fingerprint = $this->validateDraft($payload)->json('fingerprint');
        $changed = $payload;
        $changed['notes'][0]['scope'] = ['level' => 'term', 'year_level' => 1, 'semester' => 1];
        $this->assertNotSame($fingerprint, $this->validateDraft($changed)->json('fingerprint'));

        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $changed + ['request_key' => 'notes-stale-1', 'fingerprint' => $fingerprint, 'expected_settings_revision' => $this->validateDraft($payload)->json('settings_revision'), 'confirmed' => true])
            ->assertStatus(409);

        $this->commit($payload, 'notes-replay-1')->assertCreated();
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $changed + ['request_key' => 'notes-replay-1', 'fingerprint' => $this->validateDraft($changed)->json('fingerprint'), 'expected_settings_revision' => 1, 'confirmed' => true])
            ->assertStatus(409);
        $this->assertSame(1, Curriculum::count());
    }

    public function test_legacy_string_notes_and_curricula_without_notes_stay_compatible_and_copies_keep_notes(): void
    {
        $legacy = $this->payload();
        unset($legacy['notes']);
        $id = $this->commit($this->withoutErrors($legacy))->assertCreated()->json('curriculum.id');
        $this->assertSame([[
            'id' => 'legacy-1',
            'text' => self::MATH_PREP,
            'source_text' => null,
            'runs' => [['length' => mb_strlen(self::MATH_PREP), 'color' => null]],
            'scope' => ['level' => 'curriculum'],
            'provenance' => null,
            'flags' => [],
        ]], Curriculum::findOrFail($id)->notes);

        $old = Curriculum::create(['name' => 'Manual', 'code' => 'MANUAL-1', 'effective_school_year' => '2019-2020', 'department_id' => $this->department->id]);
        $this->actingAs($this->secretary)->getJson("/api/curriculum/{$old->id}")->assertOk()->assertJsonPath('notes', null);
        $this->actingAs($this->secretary)->getJson("/api/curriculum/{$old->id}/full")->assertOk()->assertJsonPath('curriculum.notes', null);

        $copy = $this->actingAs($this->secretary)->postJson("/api/curriculum/{$id}/duplicate")->assertCreated()->json('id');
        $this->assertSame(Curriculum::findOrFail($id)->notes, Curriculum::findOrFail($copy)->notes);
    }
}
