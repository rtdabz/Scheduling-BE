<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumImport;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Curriculum\CurriculumImportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CurriculumImportTest extends TestCase
{
    use RefreshDatabase;

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
    }

    /** Draft exported from the TCC BSIT reference PDF by the frontend extractor (src/lib/curriculumImport). */
    private function fixture(): array
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/tcc_bsit_import_draft.json')), true);
        $payload['metadata']['program_id'] = $this->program->id;

        return $payload;
    }

    private function saveRules(array $rules, int $lecture = 60, int $lab = 180, bool $acknowledge = false): void
    {
        $revision = (int) $this->department->fresh()->curriculum_import_settings_revision;
        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', [
            'expected_revision' => $revision,
            'lecture_minutes_per_unit' => $lecture,
            'laboratory_minutes_per_unit' => $lab,
            'rules' => $rules,
            'acknowledge_overlaps' => $acknowledge,
        ])->assertOk();
    }

    private function tccRules(): void
    {
        $this->saveRules([
            ['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major'],
            ['match_kind' => 'prefix', 'match_value' => 'PRAC', 'category' => 'major'],
            ['match_kind' => 'prefix', 'match_value' => 'GEC', 'category' => 'minor'],
            ['match_kind' => 'prefix', 'match_value' => 'GEE', 'category' => 'minor'],
            ['match_kind' => 'prefix', 'match_value' => 'PATH', 'category' => 'minor'],
            ['match_kind' => 'prefix', 'match_value' => 'ROTC', 'category' => 'minor'],
        ]);
    }

    /** Look-alike codes corrected as the reviewer would, the practicum recorded on a total-hours basis. */
    private function reviewedFixture(): array
    {
        $payload = $this->fixture();
        foreach ($payload['rows'] as &$row) {
            // Reviewer corrections of look-alike codes; the source values stay in source.code.
            $row['code'] = ['I1 125' => 'IT 125', 'PATH FITt 2' => 'PATH FIT 2', 'PATH FiIT3' => 'PATH FIT 3'][$row['code']] ?? $row['code'];
            if ($row['code'] === 'Prac 101') {
                $row['scheduling_basis'] = 'total_hours';
                $row['total_hours'] = 486;
            }
        }
        unset($row);

        return $payload;
    }

    private function validateDraft(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $payload)->assertOk();
    }

    private function acknowledgeAll(array $payload): array
    {
        $issues = collect($this->validateDraft($payload)->json('issues'));
        $this->assertSame(0, $issues->where('severity', 'error')->count(), $issues->where('severity', 'error')->pluck('message')->implode("\n"));
        $payload['acknowledgements'] = $issues->where('severity', 'review')
            ->mapWithKeys(fn (array $issue): array => [$issue['key'] => 'Checked against the signed source document.'])
            ->all();

        return $payload;
    }

    private function commitPayload(array $payload, string $key = 'import-key-0001'): array
    {
        $validation = $this->validateDraft($payload);
        $this->assertTrue($validation->json('valid'), collect($validation->json('issues'))->where('acknowledged', false)->where('severity', '!=', 'warning')->pluck('message')->implode("\n"));

        return $payload + [
            'request_key' => $key,
            'fingerprint' => $validation->json('fingerprint'),
            'expected_settings_revision' => $validation->json('settings_revision'),
        ];
    }

    public function test_reference_pdf_draft_surfaces_totals_discrepancy_and_blocking_reviews(): void
    {
        $this->tccRules();

        $response = $this->validateDraft($this->fixture());
        $issues = collect($response->json('issues'))->keyBy('key');

        $response->assertJsonPath('valid', false)
            ->assertJsonPath('totals.row_count', 59)
            ->assertJsonPath('totals.populated_groups', 10)
            ->assertJsonPath('totals.calculated', ['lec' => 147, 'lab' => 29, 'tu' => 176])
            ->assertJsonPath('totals.reported_sum.tu', 173);

        $this->assertSame('review', $issues['group_total_mismatch:3-2']['severity']);
        $this->assertStringContainsString('TU printed 27 vs calculated 30', $issues['group_total_mismatch:3-2']['message']);
        $this->assertSame(1, collect($response->json('issues'))->where('code', 'group_total_mismatch')->count());

        $practicum = collect($this->fixture()['rows'])->firstWhere('code', 'Prac 101')['row_id'];
        $this->assertSame('error', $issues['scheduling_basis_required:'.$practicum]['severity']);
        $this->assertStringContainsString('486 total hours', $issues['scheduling_basis_required:'.$practicum]['message']);

        foreach (['I1 125', 'PATH FITt 2', 'PATH FiIT3'] as $code) {
            $rowId = collect($this->fixture()['rows'])->firstWhere('code', $code)['row_id'];
            $this->assertSame('review', $issues['extraction_flag:'.$rowId]['severity']);
        }
        $this->assertSame([], collect($response->json('issues'))->filter(fn (array $issue): bool => str_starts_with($issue['code'], 'prerequisite'))->values()->all());
        $this->assertSame(['I1 125 matches no classification rule. Choose Major or Minor.'], collect($response->json('issues'))->where('code', 'unclassified')->pluck('message')->values()->all());
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, Course::count());
    }

    public function test_reviewed_reference_pdf_imports_atomically_into_a_deactivated_curriculum(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));

        $response = $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)
            ->assertCreated()
            ->assertJsonPath('curriculum.status', 'deactivated')
            ->assertJsonPath('curriculum.code', 'BSIT-2020')
            ->assertJsonPath('curriculum.cmo_number', '25')
            ->assertJsonPath('curriculum.cmo_series', 2015)
            ->assertJsonPath('curriculum.effective_school_year', '2020-2021')
            ->assertJsonPath('created_count', 59)
            ->assertJsonPath('reused_count', 0)
            ->assertJsonPath('replayed', false);

        $curriculum = Curriculum::findOrFail($response->json('curriculum.id'));
        $this->assertSame($this->program->id, (int) $curriculum->program_id);
        $this->assertSame(59, $curriculum->courses()->count());
        $this->assertSame([1, 2, 3], $curriculum->courses()->where('curriculum_course.year_level', 2)->pluck('curriculum_course.semester')->map(fn ($s) => (int) $s)->unique()->sort()->values()->all());
        $this->assertSame(0, $curriculum->courses()->where('curriculum_course.year_level', 1)->where('curriculum_course.semester', 3)->count());

        $practicum = $curriculum->courses()->where('course_code', 'PRAC 101')->firstOrFail();
        $this->assertNull($practicum->pivot->lecture_minutes);
        $snapshot = json_decode($practicum->pivot->conversion_snapshot, true);
        $this->assertSame(['basis' => 'total_hours', 'total_hours' => 486], array_intersect_key($snapshot, ['basis' => 1, 'total_hours' => 1]));
        $this->assertSame(6, (int) $practicum->units);
        $this->assertSame('100% Completed Core Courses', $practicum->pivot->prerequisites_text);

        $programming = $curriculum->courses()->where('course_code', 'IT 105')->firstOrFail();
        $this->assertSame([120, 180], [(int) $programming->pivot->lecture_minutes, (int) $programming->pivot->laboratory_minutes]);
        $this->assertSame([2, 1, 3], [(int) $programming->lecture_hours, (int) $programming->lab_hours, (int) $programming->units]);
        $this->assertSame('laboratory', $programming->room_type_required);

        $this->assertSame('IT108/109/110', $curriculum->courses()->where('course_code', 'IT 113')->firstOrFail()->pivot->prerequisites_text);
        $this->assertSame('none', $curriculum->courses()->where('course_code', 'IT 101')->firstOrFail()->pivot->prerequisites_text);

        $record = CurriculumImport::sole();
        $this->assertSame($curriculum->id, $record->curriculum_id);
        $this->assertSame('3ba201472f92e08b13cb09dea39b483abb298269337c103b7b1a978c0fc66496', $record->source_sha256);
        $this->assertArrayHasKey('group_total_mismatch:3-2', $record->record['acknowledged']);
        $corrected = collect($record->record['draft_rows'])->firstWhere('source.code', 'I1 125');
        $this->assertSame('IT 125', $corrected['code']);
        $this->assertTrue($curriculum->courses()->where('course_code', 'IT 125')->exists());

        $this->actingAs($this->secretary)->getJson('/api/curriculum/'.$curriculum->id.'/full')
            ->assertOk()
            ->assertJsonPath('curriculum.cmo_number', '25');
    }

    public function test_retries_replay_the_recorded_outcome_and_a_reused_key_with_another_body_conflicts(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));

        $first = $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)->assertCreated();
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload, ['Idempotency-Key' => 'different-transport-key'])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('curriculum.id', $first->json('curriculum.id'));

        $changed = $payload;
        $changed['metadata']['name'] = 'Another name';
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $changed)->assertStatus(409);

        $this->assertSame(1, Curriculum::count());
        $this->assertSame(1, CurriculumImport::count());
        $this->assertSame(59, Course::count());
    }

    public function test_the_internal_code_is_assigned_automatically_and_cannot_be_supplied(): void
    {
        $this->tccRules();
        Curriculum::create(['name' => 'Older BSIT', 'code' => 'BSIT-2020', 'effective_school_year' => '2020-2021', 'department_id' => $this->department->id]);

        $payload = $this->acknowledgeAll($this->reviewedFixture());
        $this->validateDraft($payload)->assertJsonPath('metadata.code', 'BSIT-2020-2');
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $this->commitPayload($payload))
            ->assertCreated()
            ->assertJsonPath('curriculum.code', 'BSIT-2020-2');

        $supplied = $payload;
        $supplied['metadata']['code'] = 'MY-CODE';
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $supplied)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata.code');
        $this->assertFalse(Curriculum::where('code', 'MY-CODE')->exists());

        $this->program->forceFill(['major' => 'Data Science'])->save();
        $this->validateDraft($payload)->assertJsonPath('metadata.code', 'BSIT-DATA-SCIENCE-2020');
        $payload['metadata']['program_id'] = null;
        $this->validateDraft($payload)->assertJsonPath('metadata.code', 'CCS-2020');
    }

    public function test_an_unresolved_row_blocks_the_whole_import(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));
        $payload['acknowledgements'] = array_slice($payload['acknowledgements'], 1, null, true);

        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('validation.valid', false);

        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, Course::count());
        $this->assertSame(0, DB::table('curriculum_course')->count());
    }

    public function test_catalog_changes_after_validation_reject_the_stale_preview(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));

        Course::create([
            'course_code' => 'GEC 1', 'course_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0,
            'units' => 3, 'course_category' => 'minor', 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->department->id,
        ]);

        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)->assertStatus(409);
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(1, Course::count());
    }

    public function test_settings_changed_since_validation_reject_the_commit(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));
        $this->saveRules([['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major']]);

        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)->assertStatus(409);
        $this->assertSame(0, Curriculum::count());
    }

    public function test_compatible_catalog_courses_are_reused_unchanged_and_mismatches_block(): void
    {
        $this->tccRules();
        $teaching = Departments::create(['department_name' => 'General Education', 'department_code' => 'GE']);
        $existing = Course::create([
            'course_code' => 'GEC 1', 'course_name' => 'Understanding the Self', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3,
            'course_category' => 'minor', 'year_level' => '1', 'semester' => '1st', 'department_id' => $this->department->id,
            'teaching_department_id' => $teaching->id,
        ]);
        $conflict = Course::create([
            'course_code' => 'GEC 2', 'course_name' => 'Readings in Philippine History', 'lecture_hours' => 2, 'lab_hours' => 1, 'units' => 3,
            'course_category' => 'minor', 'year_level' => '1', 'semester' => '2nd', 'department_id' => $this->department->id,
        ]);

        $issues = collect($this->validateDraft($this->reviewedFixture())->json('issues'));
        $rows = collect($this->validateDraft($this->reviewedFixture())->json('rows'))->keyBy('code');
        $this->assertSame('reuse', $rows['GEC 1']['catalog']['action']);
        $this->assertSame($existing->id, $rows['GEC 1']['catalog']['course_id']);
        $this->assertSame('blocked', $rows['GEC 2']['catalog']['action']);
        $this->assertTrue($issues->contains('code', 'catalog_credit_mismatch'));

        $this->assertSame([2, 1, 3], [(int) $conflict->fresh()->lecture_hours, (int) $conflict->fresh()->lab_hours, (int) $conflict->fresh()->units]);
        $this->assertSame($teaching->id, (int) $existing->fresh()->teaching_department_id);
    }

    public function test_classification_uses_exact_over_longest_prefix_with_word_boundaries(): void
    {
        $rules = collect([
            (object) ['id' => 1, 'match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major', 'enabled' => true],
            (object) ['id' => 2, 'match_kind' => 'prefix', 'match_value' => 'IT ELEC', 'category' => 'minor', 'enabled' => true],
            (object) ['id' => 3, 'match_kind' => 'exact', 'match_value' => 'IT ELEC 2', 'category' => 'major', 'enabled' => true],
            (object) ['id' => 4, 'match_kind' => 'prefix', 'match_value' => 'GEC', 'category' => 'minor', 'enabled' => false],
        ]);

        $this->assertSame(1, CurriculumImportSettings::classify('IT 101', $rules)['id']);
        $this->assertSame(1, CurriculumImportSettings::classify('IT101', $rules)['id']);
        $this->assertSame(2, CurriculumImportSettings::classify('IT ELEC 1', $rules)['id']);
        $this->assertSame(3, CurriculumImportSettings::classify('IT ELEC 2', $rules)['id']);
        $this->assertNull(CurriculumImportSettings::classify('ITEM 101', $rules));
        $this->assertNull(CurriculumImportSettings::classify('GEC 1', $rules));
    }

    public function test_unclassified_rows_are_never_defaulted_to_major(): void
    {
        $this->saveRules([['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major']]);
        $issues = collect($this->validateDraft($this->reviewedFixture())->json('issues'));

        $this->assertTrue($issues->where('code', 'unclassified')->isNotEmpty());
        $this->assertFalse($issues->where('code', 'unclassified')->pluck('message')->contains(fn ($message) => str_contains($message, 'IT 101')));
    }

    public function test_conversion_minutes_follow_department_rates_without_double_multiplication(): void
    {
        $this->tccRules();
        $rows = collect($this->validateDraft($this->reviewedFixture())->json('rows'))->keyBy('code');
        $this->assertSame([120, 180, 300, 3], [$rows['IT 101']['lecture_minutes'], $rows['IT 101']['laboratory_minutes'], $rows['IT 101']['weekly_contact_minutes'], $rows['IT 101']['total_credits']]);

        $this->saveRules([['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major']], 60, 120);
        $response = $this->validateDraft($this->reviewedFixture());
        $rows = collect($response->json('rows'))->keyBy('code');
        $this->assertSame([120, 120, 240], [$rows['IT 101']['lecture_minutes'], $rows['IT 101']['laboratory_minutes'], $rows['IT 101']['weekly_contact_minutes']]);
        $this->assertFalse(collect($response->json('issues'))->contains('key', 'conversion_rates_not_scheduled'));
    }

    public function test_settings_require_the_secretary_and_reject_duplicates_unacknowledged_overlaps_and_stale_revisions(): void
    {
        $head = User::factory()->create(['role' => 'program_head', 'department_id' => $this->department->id, 'program_id' => $this->program->id])->fresh();
        $this->actingAs($head)->getJson('/api/curriculum-import-settings')->assertOk()->assertJsonPath('can_manage', false);
        $this->actingAs($head)->putJson('/api/curriculum-import-settings', [
            'expected_revision' => 1, 'lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180, 'rules' => [],
        ])->assertForbidden();

        $base = ['expected_revision' => 1, 'lecture_minutes_per_unit' => 60, 'laboratory_minutes_per_unit' => 180];
        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', $base + ['rules' => [
            ['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major'],
            ['match_kind' => 'prefix', 'match_value' => ' it ', 'category' => 'minor'],
        ]])->assertUnprocessable();

        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', array_replace($base, ['lecture_minutes_per_unit' => 75, 'rules' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('lecture_minutes_per_unit');

        $overlapping = $base + ['rules' => [
            ['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'major'],
            ['match_kind' => 'exact', 'match_value' => 'IT Elec 1', 'category' => 'minor'],
        ]];
        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', $overlapping)
            ->assertUnprocessable()->assertJsonValidationErrors('acknowledge_overlaps');
        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', $overlapping + ['acknowledge_overlaps' => true])
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('overlaps.0.specific', 'IT ELEC 1');

        $this->actingAs($this->secretary)->putJson('/api/curriculum-import-settings', $overlapping + ['acknowledge_overlaps' => true])
            ->assertStatus(409);
    }

    public function test_rate_changes_never_rewrite_existing_placements_or_courses(): void
    {
        $this->tccRules();
        $payload = $this->commitPayload($this->acknowledgeAll($this->reviewedFixture()));
        $curriculumId = $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $payload)->assertCreated()->json('curriculum.id');
        $before = DB::table('curriculum_course')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $courses = Course::query()->orderBy('id')->get(['id', 'lecture_hours', 'lab_hours', 'units', 'course_category'])->toArray();

        $this->saveRules([['match_kind' => 'prefix', 'match_value' => 'IT', 'category' => 'minor']], 90, 120);

        $this->assertSame($before, DB::table('curriculum_course')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($courses, Course::query()->orderBy('id')->get(['id', 'lecture_hours', 'lab_hours', 'units', 'course_category'])->toArray());

        $copy = $this->actingAs($this->secretary)->postJson('/api/curriculum/'.$curriculumId.'/duplicate')->assertCreated();
        $copied = DB::table('curriculum_course')->where('curriculum_id', $copy->json('id'))->get()->keyBy('course_id');
        foreach (DB::table('curriculum_course')->where('curriculum_id', $curriculumId)->get() as $original) {
            $this->assertSame($original->conversion_snapshot, $copied[$original->course_id]->conversion_snapshot);
            $this->assertSame($original->lecture_minutes, $copied[$original->course_id]->lecture_minutes);
        }
    }

    public function test_program_heads_import_only_into_their_own_program_and_deans_cannot_import(): void
    {
        $other = Program::create(['department_id' => $this->department->id, 'code' => 'BSCS', 'name' => 'Computer Science']);
        $head = User::factory()->create(['role' => 'program_head', 'department_id' => $this->department->id, 'program_id' => $other->id])->fresh();
        $this->tccRules();

        $payload = $this->acknowledgeAll($this->reviewedFixture());
        $validation = $this->actingAs($head)->postJson('/api/curriculum-imports/validate', $payload)->assertOk();
        $validation->assertJsonPath('metadata.program_id', $other->id);

        $dean = User::factory()->create(['role' => 'dean', 'department_id' => $this->department->id]);
        $dean->assignRole('dean');
        $this->actingAs($dean->fresh())->postJson('/api/curriculum-imports/validate', $payload)->assertForbidden();
        $this->actingAs($dean->fresh())->getJson('/api/curriculum-import-settings')->assertForbidden();
    }

    public function test_prerequisites_are_stored_as_reference_text_without_review_or_validation(): void
    {
        $this->tccRules();
        $payload = $this->reviewedFixture();
        $byCode = fn (string $code): int => collect($payload['rows'])->search(fn (array $row): bool => $row['code'] === $code);

        // Text that once needed classification: self, cross-term and unknown references, a condition, blanks.
        $payload['rows'][$byCode('IT 101')]['prerequisites'] = 'IT 101 / IT 999 or 3rd Year Standing';
        $payload['rows'][$byCode('IT 102')]['prerequisites'] = 'Prac 101';
        $payload['rows'][$byCode('IT 103')]['prerequisites'] = '';
        $payload['rows'][$byCode('GEC 1')]['prerequisites'] = null;
        // A corrected extraction error; the document's value stays in source.prerequisites.
        $payload['rows'][$byCode('IT 113')]['prerequisites'] = 'IT 108/109/110';

        $issues = collect($this->validateDraft($payload)->json('issues'));
        $this->assertSame([], $issues->filter(fn (array $issue): bool => str_starts_with($issue['code'], 'prerequisite') || $issue['field'] === 'prerequisites')->values()->all());

        $response = $this->actingAs($this->secretary)->postJson('/api/curriculum-imports', $this->commitPayload($this->acknowledgeAll($payload)))->assertCreated();

        $placements = Curriculum::findOrFail($response->json('curriculum.id'))->courses()->get()->keyBy('course_code');
        $this->assertSame('IT 101 / IT 999 or 3rd Year Standing', $placements['IT 101']->pivot->prerequisites_text);
        $this->assertSame('Prac 101', $placements['IT 102']->pivot->prerequisites_text);
        $this->assertNull($placements['IT 103']->pivot->prerequisites_text);
        $this->assertNull($placements['GEC 1']->pivot->prerequisites_text);
        $this->assertSame('IT 108/109/110', $placements['IT 113']->pivot->prerequisites_text);

        $recorded = collect(CurriculumImport::sole()->record['draft_rows'])->firstWhere('code', 'IT 113');
        $this->assertSame('IT108/109/110', $recorded['source']['prerequisites']);
    }

    public function test_draft_level_rules_reject_duplicates_and_fractions(): void
    {
        $this->tccRules();
        $payload = $this->reviewedFixture();
        $first = $payload['rows'][0];
        $second = $payload['rows'][1];

        $payload['rows'][1]['code'] = $first['code'];
        $issues = collect($this->validateDraft($payload)->json('issues'));
        $this->assertTrue($issues->contains('key', 'duplicate_code:'.$first['row_id']));
        $this->assertTrue($issues->contains('key', 'duplicate_code:'.$second['row_id']));

        $fractional = $this->reviewedFixture();
        $fractional['rows'][0]['lecture_units'] = 1.5;
        $this->actingAs($this->secretary)->postJson('/api/curriculum-imports/validate', $fractional)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rows.0.lecture_units');
    }

}
