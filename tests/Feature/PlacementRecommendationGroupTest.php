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
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\Recommendations\GenerationAdjustmentInterpreter;
use App\Services\Scheduling\Recommendations\Providers\PlacementRecommendationProvider;
use App\Services\Scheduling\Recommendations\RecommendationContext;
use App\Services\Scheduling\Recommendations\RecommendationEngine;
use App\Services\Scheduling\Recommendations\RecommendationSource;
use App\Services\Scheduling\Recommendations\SessionInterpreter;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\Scheduling\Support\SchedulingSnapshotRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlacementRecommendationGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_pair_options_validate_the_partner_and_save_the_exact_reviewed_group(): void
    {
        $f = $this->fixture();
        $rows = $this->pair($f);
        $this->booking($f, 'Wednesday', '08:00', '09:30');
        $response = $this->manual($f, $rows)->assertOk();
        $response->assertJsonPath('recommendations', []);
        $this->assertNotEmpty($response->json('slots'), 'Legacy exhaustive discovery remains available.');
        $placements = $response->json('placements');
        $this->assertNotEmpty($placements);
        foreach ($placements as $slot) {
            $this->assertCount(2, $slot['group_rows']);
            $this->assertSame($slot['group_rows'][0]['start_time'], $slot['group_rows'][1]['start_time']);
            $this->assertFalse($slot['start_time'] < '09:30' && $slot['end_time'] > '08:00');
        }
        $this->assertSame(count($placements), $response->json('placement_total'));
        $this->assertSame(count($placements), array_sum(array_column($response->json('placement_rooms'), 'slot_count')));
        $this->assertDatabaseCount('schedules', 1);
        $this->save($f, $placements[0]['group_rows'])->assertSuccessful();
        $this->assertDatabaseCount('schedules', 3);
    }

    public function test_a_free_primary_interval_does_not_prove_a_blocked_partner_or_incomplete_group(): void
    {
        $f = $this->fixture();
        $this->booking($f, 'Wednesday', '00:00', '23:30');
        $response = $this->manual($f, $this->pair($f))->assertOk();
        $this->assertNotEmpty($response->json('slots'));
        $this->assertSame([], $response->json('placements'));
        $partial = [$this->pair($f)[0]];
        $partial[0]['preferred_pattern'] = 'consecutive:3';
        $this->assertSame([], $this->manual($f, $partial, ['consecutive_days' => 3])->assertOk()->json('placements'));
    }

    public function test_rescheduling_replaces_only_the_affected_persisted_group(): void
    {
        $f = $this->fixture();
        $rows = $this->pair($f);
        $existing = $this->save($f, array_map(fn (array $row): array => $this->identified($f, $row), $rows))
            ->assertSuccessful()->json('schedules');
        $other = $this->booking($f, 'Wednesday', '08:00', '09:30');
        $unsaved = [...$this->identified($f, $rows[1]), 'course_id' => $f['other']->id,
            'start_time' => '13:00', 'end_time' => '15:00', 'preferred_pattern' => null, 'split_group_id' => null];
        $placements = $this->manual($f, $rows, ['ignore_schedule_ids' => array_column($existing, 'id'),
            'tentative_schedules' => [...$existing, $other->toArray(), $unsaved]])
            ->assertOk()->json('placements');
        $this->assertNotEmpty($placements, 'The old group must not consume its own replacement duration.');
        foreach ($placements as $slot) {
            $start = substr($slot['start_time'], 0, 5);
            $end = substr($slot['end_time'], 0, 5);
            $this->assertFalse($start < '09:30' && $end > '08:00');
            $this->assertFalse($start < '15:00' && $end > '13:00', 'The unsaved partner-day booking must still block the group.');
        }
        $operations = array_map(static fn (array $row, int $index): array => [...$row, 'id' => $existing[$index]['id']],
            $placements[0]['group_rows'], [0, 1]);
        $this->save($f, $operations)->assertSuccessful();
        $this->assertDatabaseCount('schedules', 3);
        $this->assertSame($other->start_time, $other->fresh()->start_time);
    }

    public function test_manual_and_draft_share_group_options_and_verification_for_identical_contexts(): void
    {
        $f = $this->fixture();
        $this->booking($f, 'Monday', '08:00', '09:30');
        $this->booking($f, 'Wednesday', '08:00', '09:30');
        $rows = $this->pair($f);
        $this->manual($f, $rows)->assertOk()->assertJsonPath('recommendations', []);
        $manual = $this->catalog($f, $rows);
        $draftRows = array_map(fn (array $row): array => $this->identified($f, $row), $rows);
        $result = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::DraftReview, [
            'semesterId' => $f['semester']->id, 'departmentId' => $f['department']->id,
            'sectionIds' => [$f['section']->id], 'rows' => $draftRows,
        ]));
        $draft = $result->legacyPayload['issues'][0]['options'];
        $normalize = static fn (array $options): array => array_map(static fn (array $option): array => [
            $option['label'], $option['rank'], $option['score'], array_map(static fn (array $row): array => [
                $row['day'], $row['start_time'], $row['end_time'], $row['mode'], $row['room_id'], $row['meeting_type'],
            ], $option['rows']),
        ], $options);
        $this->assertNotEmpty($draft);
        $this->assertSame($normalize($draft), $normalize($manual));
        foreach ($result->options as $option) {
            $this->assertSame('verified_group', $option->verificationStatus);
            $this->assertSame('affected_course_group', $option->verificationScope);
            $this->assertFalse($option->toArray()['verification']['complete_timetable_verified']);
        }
    }

    public function test_unplaced_request_local_runs_keep_the_complete_day_count_and_save_without_a_saved_rule(): void
    {
        $f = $this->fixture();
        $this->assertDatabaseCount('department_course_rules', 0);
        $options = $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/draft-review', [
            'semester_id' => $f['semester']->id, 'department_id' => $f['department']->id,
            'section_ids' => [$f['section']->id], 'rows' => [], 'unplaced' => [[
                'section_id' => $f['section']->id, 'course_id' => $f['course']->id,
                'consecutive_rule' => ['day_count' => 3, 'meeting_days' => ['Tuesday', 'Wednesday', 'Thursday']],
                'meetings' => [['duration_slots' => 8, 'meeting_type' => null, 'modes' => ['on-site']]],
            ]],
        ])->assertOk()->json('issues.0.options');
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $this->assertCount(3, $option['rows']);
            $this->assertSame(['Tuesday', 'Wednesday', 'Thursday'], array_column($option['rows'], 'day'));
            $this->assertSame(['consecutive:3'], array_unique(array_column($option['rows'], 'preferred_pattern')));
            $this->assertNull($option['label']);
        }
        $this->save($f, $options[0]['rows'])->assertSuccessful();
        $this->assertDatabaseCount('department_course_rules', 0);
    }

    public function test_manual_runs_include_every_day_and_reject_a_blocked_day(): void
    {
        $f = $this->fixture();
        $rows = array_map(fn (string $day): array => [...$this->pair($f)[0], 'day' => $day, 'preferred_pattern' => 'consecutive:3'], ['Monday', 'Tuesday', 'Wednesday']);
        $response = $this->manual($f, $rows, ['consecutive_days' => 3])->assertOk();
        $this->assertNotEmpty($response->json('placements'));
        foreach ($response->json('placements') as $slot) {
            $this->assertCount(3, $slot['group_rows']);
            $this->assertSame($slot['run_days'], array_column($slot['group_rows'], 'day'));
        }
        $this->booking($f, 'Tuesday', '00:00', '23:30');
        $response = $this->manual($f, $rows, ['consecutive_days' => 3])->assertOk();
        $slots = $response->json('placements');
        foreach ($slots as $slot) {
            $this->assertNotContains('Tuesday', $slot['run_days']);
        }
        $catalog = $this->catalog($f, $rows);
        $this->assertNotEmpty($catalog);
        foreach ($catalog as $option) {
            $this->assertSame([1, 2, 3], array_column($option['rows'], 'meeting_index'));
        }
    }

    public function test_mismatched_pair_lengths_are_never_reported_as_verified_placements(): void
    {
        $f = $this->fixture();
        $rows = $this->pair($f);
        $rows[1]['end_time'] = '10:00';
        $response = $this->manual($f, $rows)->assertOk();
        $this->assertSame([], $response->json('placements'));
        $runRows = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'consecutive:2'], $rows);
        $response = $this->manual($f, $runRows, ['consecutive_days' => 2])->assertOk();
        $this->assertSame([], $this->catalog($f, $runRows), 'Run fixes must retain every supplied meeting length.');
        $runRows[1]['end_time'] = '09:30';
        $runRows[1]['preferred_pattern'] = 'consecutive:3';
        $response = $this->manual($f, $runRows, ['consecutive_days' => 2])->assertOk();
        $this->assertSame([], $response->json('placements'));
        $this->assertSame([], $this->catalog($f, $runRows), 'Conflicting expected run counts must stay unresolved.');
    }

    public function test_group_context_cannot_ignore_another_courses_persisted_occupancy(): void
    {
        $f = $this->fixture();
        $other = $this->booking($f, 'Monday', '08:00', '09:30');
        $this->manual($f, $this->pair($f), ['ignore_schedule_ids' => [$other->id]])->assertStatus(422);
        $this->manual($f, $this->pair($f), ['placement' => ['rows' => $this->pair($f), 'selected_meeting' => 6]])->assertStatus(422);
        $this->manual($f, $this->pair($f), ['consecutive_days' => 3])->assertStatus(422);
        $untrusted = $this->pair($f);
        $untrusted[0]['id'] = $other->id;
        $this->manual($f, $untrusted)->assertStatus(422);
    }

    public function test_integrated_lecture_options_keep_the_laboratory_and_its_independent_length(): void
    {
        $f = $this->fixture();
        $f['course']->update(['lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory', 'course_category' => 'major']);
        $lab = Rooms::create(['room_code' => 'IT LAB', 'building' => 'IT', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $f['department']->id]);
        $rows = [
            ['day' => 'Tuesday', 'start_time' => '10:00', 'end_time' => '13:00', 'mode' => 'on-site', 'room_id' => $lab->id,
                'is_hybrid' => true, 'preferred_pattern' => 'days:1-2', 'meeting_type' => 'laboratory'],
            ['day' => 'Wednesday', 'start_time' => '08:00', 'end_time' => '10:00', 'mode' => 'online', 'room_id' => null,
                'is_hybrid' => true, 'preferred_pattern' => 'days:1-2', 'meeting_type' => 'lecture'],
        ];
        $response = $this->manual($f, $rows, ['duration_slots' => 4, 'meeting_type' => 'lecture',
            'excluded_days' => ['Tuesday'], 'placement' => ['rows' => $rows, 'selected_meeting' => 1]])->assertOk();
        $placements = $response->json('placements');
        $this->assertNotEmpty($placements);
        foreach ($placements as $slot) {
            $this->assertSame('Tuesday', $slot['group_rows'][0]['day']);
            $this->assertSame('10:00', $slot['group_rows'][0]['start_time']);
            $this->assertSame('13:00', $slot['group_rows'][0]['end_time']);
            $this->assertSame($lab->id, $slot['group_rows'][0]['room_id']);
        }
        $online = collect($placements)->firstWhere('mode', 'online');
        $this->assertNotNull($online);
        $this->save($f, $online['group_rows'])->assertSuccessful();
    }

    public function test_partial_consecutive_repair_retains_the_kept_meeting_and_saves_a_complete_run(): void
    {
        $f = $this->fixture();
        $this->booking($f, 'Monday', '08:30', '10:00');
        $rows = array_map(fn (string $day): array => $this->identified($f, [
            ...$this->pair($f)[0], 'day' => $day, 'start_time' => '08:30', 'end_time' => '10:00', 'preferred_pattern' => 'consecutive:2',
        ]), ['Monday', 'Tuesday']);
        $options = $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/draft-review', [
            'semester_id' => $f['semester']->id, 'department_id' => $f['department']->id, 'section_ids' => [$f['section']->id], 'rows' => $rows,
        ])->assertOk()->json('issues.0.options');
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $this->assertCount(2, $option['rows']);
            $kept = collect($option['rows'])->firstWhere('day', 'Tuesday');
            $this->assertNotNull($kept);
            $this->assertSame('08:30:00', $kept['start_time']);
            $this->assertSame('10:00:00', $kept['end_time']);
            $this->assertSame($f['room']->id, $kept['room_id']);
            $this->assertNull($option['label']);
        }
        $this->save($f, $options[0]['rows'])->assertSuccessful();
    }

    public function test_manual_request_local_ticked_days_are_preserved_without_persisting_a_rule(): void
    {
        $f = $this->fixture();
        $rows = array_map(static fn (array $row): array => [...$row, 'preferred_pattern' => 'consecutive:2'], $this->pair($f));
        $options = $this->manual($f, $rows, ['consecutive_days' => 2, 'placement' => ['rows' => $rows, 'selected_meeting' => 0,
            'consecutive_rule' => ['day_count' => 2, 'meeting_days' => ['Monday', 'Wednesday']]]])->assertOk()->json('placements');
        $this->assertNotEmpty($options);
        foreach ($options as $slot) {
            $this->assertSame(['Monday', 'Wednesday'], array_column($slot['group_rows'], 'day'));
        }
        $this->save($f, $options[0]['group_rows'])->assertSuccessful();
        $this->assertDatabaseCount('department_course_rules', 0);
    }

    public function test_manual_single_meetings_reuse_discovery_counts_and_keep_group_verification(): void
    {
        $f = $this->fixture();
        $rows = [[...$this->pair($f)[0], 'preferred_pattern' => null]];
        $response = $this->manual($f, $rows)->assertOk()->assertJsonPath('recommendations', []);
        $this->assertNotEmpty($response->json('placements'));
        $this->assertSame(count($response->json('slots')), $response->json('placement_total'));
        $this->assertSame($response->json('rooms'), $response->json('placement_rooms'));
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($f['semester']->id, $f['department']->id, [$f['section']->id], [$f['course']->id]);
        $result = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::ManualPlacement, [
            'snapshot' => $snapshot, 'sectionId' => $f['section']->id, 'courseId' => $f['course']->id,
            'durationSlots' => 3, 'meetingType' => 'lecture',
            'placement' => ['rows' => [[...$this->identified($f, $rows[0]), 'split_group_id' => null]], 'selected_meeting' => 0],
        ]));
        $this->assertNotEmpty($result->options);
        foreach ($result->options as $option) {
            $this->assertCount(1, $option->payload['group_rows']);
            $this->assertSame('verified_group', $option->verificationStatus);
            $this->assertSame('affected_course_group', $option->verificationScope);
            $this->assertFalse($option->toArray()['verification']['complete_timetable_verified']);
        }
    }

    public static function regularDeliveries(): array
    {
        return [['on-site', 'Split'], ['online', 'Online (All)']];
    }

    #[DataProvider('regularDeliveries')]
    public function test_fragmented_regular_enhancements_are_shared_and_save(string $mode, string $label): void
    {
        $f = $this->fixture();
        $rows = [[...$this->pair($f)[0], 'start_time' => '07:00', 'end_time' => '10:00',
            'preferred_pattern' => null, 'mode' => $mode, 'room_id' => $mode === 'online' ? null : $f['room']->id]];
        $payload = ['duration_slots' => 6, 'placement' => ['rows' => $rows, 'selected_meeting' => 0,
            'session_alternatives' => true, 'allowed_days' => ['Monday', 'Wednesday']]];
        $this->assertSame([], $this->manual($f, $rows, $payload)->assertOk()->json('recommendations'), 'No blocker means no enhancement.');
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, in_array($day, ['Monday', 'Wednesday'], true) ? '08:30' : '00:00', '23:30');
        }
        $options = $this->manual($f, $rows, $payload)->assertOk()->json('recommendations');
        $this->assertNotEmpty($options);
        $this->assertDatabaseCount('schedules', 7);
        foreach ($options as $option) {
            $this->assertSame($label, $option['label']);
            $this->assertCount(2, $option['rows']);
            $this->assertSame([$mode, $mode], array_column($option['rows'], 'mode'));
            $this->assertSame(['07:00:00', '07:00:00'], array_column($option['rows'], 'start_time'));
            $this->assertSame(['08:30:00', '08:30:00'], array_column($option['rows'], 'end_time'));
        }
        $draft = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::DraftReview, [
            'semesterId' => $f['semester']->id, 'departmentId' => $f['department']->id,
            'sectionIds' => [$f['section']->id], 'rows' => [[...$this->identified($f, $rows[0]), 'split_group_id' => null]],
            'preferredDays' => ['Monday', 'Wednesday'],
        ]))->legacyPayload;
        $this->assertNotEmpty(array_filter($draft['issues'][0]['options'], static fn ($option) => ($option['adjustment_type'] ?? '') === 'enable_balanced_split'));
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($f['semester']->id, $f['department']->id, [$f['section']->id], [$f['course']->id]);
        $config = ['course_ids' => [$f['course']->id], 'delivery_modes_by_course_id' => [$f['course']->id => $mode],
            'allowed_days' => ['Monday', 'Wednesday'], 'requirements_by_course_id' => [$f['course']->id => [
                ['component_type' => 'lecture', 'duration_slots' => 6, 'allowed_delivery_modes' => [$mode]],
            ]]];
        $generation = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Search, [
            'snapshot' => $snapshot, 'bottleneck' => null, 'strategies' => [], 'configsBySectionId' => [$f['section']->id => $config],
        ]))->legacyPayload;
        $draftSnapshot = $snapshot->toArray();
        $draftSnapshot['persisted_schedules'] = [];
        $probeInput = ['snapshot' => SchedulingSnapshot::fromArray($draftSnapshot),
            'bottleneck' => null, 'strategies' => [], 'configsBySectionId' => [$f['section']->id => $config],
            'probeDraft' => ['schedules' => Schedule::all()->toArray(), 'unplaced_courses' => [['section_id' => $f['section']->id, 'course_id' => $f['course']->id]]]];
        $withDraft = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Search, $probeInput))->legacyPayload;
        $this->assertNotNull(collect($withDraft)->firstWhere('title', $label), 'Unplaced alternatives must account for the staged partial draft.');
        $expired = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Search, [...$probeInput, 'probeDeadline' => microtime(true) - 1]))->legacyPayload;
        $this->assertNull(collect($expired)->firstWhere('title', $label));
        $enhanced = collect($generation)->firstWhere('title', $label);
        $this->assertNotNull($enhanced);
        $this->assertTrue($enhanced['apply_individually']);
        $applied = (new GenerationAdjustmentInterpreter)->apply([$f['section']->id => $config], $enhanced['adjustments']);
        $this->assertSame([$f['course']->id], $applied['configs'][$f['section']->id]['balanced_split_course_ids']);
        $this->assertSame($mode, $applied['configs'][$f['section']->id]['delivery_modes_by_course_id'][$f['course']->id]);
        $restricted = $payload;
        $restricted['placement']['allowed_days'] = ['Monday'];
        $this->assertSame([], $this->manual($f, $rows, $restricted)->assertOk()->json('recommendations'));
        $restricted['placement']['allowed_days'] = ['Monday', 'Wednesday'];
        $restricted['placement']['rows'][0]['preferred_pattern'] = 'consecutive:2';
        $this->assertSame([], $this->manual($f, $rows, $restricted)->assertOk()->json('recommendations'));
        if ($mode === 'online') {
            $otherSection = $f['section']->replicate();
            $otherSection->section_name = 'Other';
            $otherSection->save();
            $online = Schedule::create([...$this->identified($f, []), 'section_id' => $otherSection->id,
                'day' => 'Wednesday', 'start_time' => '07:00', 'end_time' => '08:30', 'mode' => 'online']);
            $this->assertSame([], $this->manual($f, $rows, $payload)->assertOk()->json('recommendations'), 'The same online course cannot overlap another section.');
            $online->delete();
        }
        $old = Schedule::create([...$this->identified($f, $rows[0]), 'split_group_id' => null]);
        $editing = $this->manual($f, $rows, [...$payload, 'ignore_schedule_ids' => [$old->id], 'tentative_schedules' => [$old->toArray()]])->assertOk()->json('recommendations');
        $this->assertNotEmpty($editing, 'Ignore precisely the replaced persisted row even if it is also in the displayed timetable.');
        $saveRows = $editing[0]['rows'];
        $saveRows[0]['id'] = $old->id;
        $this->save($f, $saveRows)->assertSuccessful();
    }

    public function test_draft_review_returns_exactly_one_online_all_option_for_fragmented_time(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 2, 'lecture_hours' => 2]);
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, in_array($day, ['Monday', 'Wednesday'], true) ? '08:00' : '00:00', '23:30');
        }
        $row = [...$this->identified($f, $this->pair($f)[0]), 'start_time' => '07:00', 'end_time' => '09:00',
            'mode' => 'online', 'room_id' => null, 'preferred_pattern' => null, 'split_group_id' => null];
        $options = $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/draft-review', [
            'semester_id' => $f['semester']->id, 'department_id' => $f['department']->id,
            'section_ids' => [$f['section']->id], 'rows' => [$row],
        ])->assertOk()->json('issues.0.options');
        $online = array_values(array_filter($options, static fn (array $option): bool => $option['label'] === 'Online (All)'));
        $this->assertCount(1, $online);
        $this->assertSame('enable_balanced_split', $online[0]['adjustment_type']);
        $this->assertSame(['Monday', 'Wednesday'], array_column($online[0]['rows'], 'day'));
        $this->assertSame(['07:00:00', '07:00:00'], array_column($online[0]['rows'], 'start_time'));
        $this->assertSame(['08:00:00', '08:00:00'], array_column($online[0]['rows'], 'end_time'));
        $this->assertSame(['online', 'online'], array_column($online[0]['rows'], 'mode'));
    }

    public function test_draft_configured_fits_returns_no_enhancement_for_regular_or_integrated_groups(): void
    {
        $f = $this->fixture();
        $row = [...$this->pair($f)[0], 'start_time' => '07:00', 'end_time' => '09:00', 'preferred_pattern' => null];
        foreach (['on-site', 'online'] as $mode) {
            $options = $this->catalog($f, [[...$row, 'mode' => $mode, 'room_id' => $mode === 'online' ? null : $f['room']->id]]);
            $this->assertNotEmpty($options);
            $this->assertSame([], array_filter($options, static fn (array $option): bool => isset($option['adjustment_type'])));
        }
        $f['course']->update(['course_category' => 'major', 'lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $lab = Rooms::create(['room_code' => 'LAB', 'building' => 'IT', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $f['department']->id]);
        $options = $this->catalog($f, [$row, [...$row, 'day' => 'Wednesday', 'end_time' => '10:00', 'meeting_type' => 'laboratory', 'room_id' => $lab->id]]);
        $this->assertNotEmpty($options);
        $this->assertSame([], array_filter($options, static fn (array $option): bool => isset($option['adjustment_type'])));
    }

    public function test_draft_configured_options_outside_preferred_days_do_not_hide_enhancements(): void
    {
        $f = $this->fixture();
        $f['course']->update(['units' => 2, 'lecture_hours' => 2]);
        foreach (['Monday', 'Wednesday'] as $day) {
            $this->booking($f, $day, '08:00', '23:30');
        }
        $draft = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::DraftReview, [
            'semesterId' => $f['semester']->id, 'departmentId' => $f['department']->id,
            'sectionIds' => [$f['section']->id], 'rows' => [], 'preferredDays' => ['Monday', 'Wednesday'],
            'unplaced' => [['section_id' => $f['section']->id, 'course_id' => $f['course']->id,
                'meetings' => [['meeting_type' => 'lecture', 'duration_slots' => 4, 'modes' => ['online']]]]],
        ]))->legacyPayload;
        $options = $draft['issues'][0]['options'];
        $this->assertNotEmpty(array_filter($options, static fn (array $option): bool => ! isset($option['adjustment_type'])));
        $enhancements = array_values(array_filter($options, static fn (array $option): bool => isset($option['adjustment_type'])));
        $this->assertCount(1, $enhancements);
        $this->assertSame(['Monday', 'Wednesday'], array_column($enhancements[0]['rows'], 'day'));
    }

    public function test_enhancements_refuse_disallowed_days_and_incomplete_room_or_online_pairs(): void
    {
        $f = $this->fixture();
        $row = [...$this->pair($f)[0], 'start_time' => '07:00', 'end_time' => '10:00', 'preferred_pattern' => null];
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, $day === 'Monday' ? '08:30' : '00:00', '23:30');
        }
        foreach (['on-site', 'online'] as $mode) {
            $rows = [[...$row, 'mode' => $mode, 'room_id' => $mode === 'online' ? null : $f['room']->id]];
            $this->assertSame([], $this->manual($f, $rows, ['duration_slots' => 6, 'placement' => [
                'rows' => $rows, 'selected_meeting' => 0, 'session_alternatives' => true,
                'allowed_days' => ['Monday'],
            ]])->assertOk()->json('recommendations'));
        }
    }

    public function test_integrated_enhancement_changes_only_lecture_delivery_and_needs_a_lab(): void
    {
        $f = $this->fixture();
        $f['course']->update(['course_category' => 'major', 'lecture_hours' => 2, 'lab_hours' => 1, 'room_type_required' => 'laboratory']);
        $lab = Rooms::create(['room_code' => 'LAB', 'building' => 'IT', 'room_type' => 'laboratory', 'status' => 'available', 'department_id' => $f['department']->id]);
        $otherSection = $f['section']->replicate();
        $otherSection->section_name = 'Other';
        $otherSection->save();
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, '00:00', '23:30')->update(['section_id' => $otherSection->id]);
        }
        $rows = [
            [...$this->pair($f)[0], 'start_time' => '07:00', 'end_time' => '09:00', 'preferred_pattern' => 'days:0-2'],
            [...$this->pair($f)[1], 'start_time' => '07:00', 'end_time' => '10:00', 'meeting_type' => 'laboratory', 'room_id' => $lab->id, 'preferred_pattern' => 'days:0-2'],
        ];
        $payload = ['duration_slots' => 4, 'placement' => ['rows' => $rows, 'selected_meeting' => 0, 'session_alternatives' => true]];
        $options = $this->manual($f, $rows, $payload)->assertOk()->json('recommendations');
        $this->assertNotEmpty($options);
        $this->assertSame('Integrated Hybrid', $options[0]['label']);
        $this->assertSame(['online', 'on-site'], array_column($options[0]['rows'], 'mode'));
        $this->assertSame([null, $lab->id], array_column($options[0]['rows'], 'room_id'));
        $draft = $this->catalog($f, $rows);
        $this->assertNotEmpty(array_filter($draft, static fn ($option) => ($option['label'] ?? '') === 'Integrated Hybrid'));
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($f['semester']->id, $f['department']->id, [$f['section']->id], [$f['course']->id]);
        $config = ['course_ids' => [$f['course']->id], 'selected_split_session_course_ids' => [$f['course']->id],
            'delivery_modes_by_course_id' => [$f['course']->id => 'on-site'], 'requirements_by_course_id' => [$f['course']->id => [
                ['component_type' => 'lecture', 'duration_slots' => 4, 'allowed_delivery_modes' => ['on-site']],
                ['component_type' => 'laboratory', 'duration_slots' => 6, 'allowed_delivery_modes' => ['on-site']],
            ]]];
        $generation = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Search, [
            'snapshot' => $snapshot, 'bottleneck' => null, 'strategies' => [], 'configsBySectionId' => [$f['section']->id => $config],
        ]))->legacyPayload;
        $this->assertNotNull(collect($generation)->firstWhere('title', 'Integrated Hybrid'));
        $capacity = app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Feasibility, [
            'snapshot' => $snapshot, 'blockingConstraints' => [['code' => 'insufficient_room_slots']], 'configsBySectionId' => [$f['section']->id => $config],
        ]))->legacyPayload;
        $this->assertNotNull(collect($capacity)->firstWhere('title', 'Integrated Hybrid'));
        $this->save($f, $options[0]['rows'])->assertSuccessful();
        $ids = Schedule::where('course_id', $f['course']->id)->pluck('id')->all();
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, '00:00', '23:30')->update(['section_id' => $otherSection->id, 'room_id' => $lab->id]);
        }
        $this->assertSame([], $this->manual($f, $rows, [...$payload, 'ignore_schedule_ids' => $ids])->assertOk()->json('recommendations'), 'An online lecture does not fix a laboratory shortage.');
    }

    #[DataProvider('enhancementFallbackDays')]
    public function test_generation_enhancements_use_solver_fallback_pairs(array $days, bool $sunday, bool $expected): void
    {
        $f = $this->fixture();
        $f['department']->forceFill(['sunday_classes_enabled' => $sunday])->save();
        foreach (SchedulingPolicy::PERSISTABLE_DAYS as $day) {
            $this->booking($f, $day, in_array($day, $days, true) ? '08:30' : '00:00', '23:30');
        }
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($f['semester']->id, $f['department']->id, [$f['section']->id], [$f['course']->id]);
        $config = ['course_ids' => [$f['course']->id], 'delivery_modes_by_course_id' => [$f['course']->id => 'online'],
            'allowed_days' => $days, 'requirements_by_course_id' => [$f['course']->id => [
                ['component_type' => 'lecture', 'duration_slots' => 6, 'allowed_delivery_modes' => ['online']],
            ]]];
        $recommend = fn (SchedulingSnapshot $state, array $configuration): array => app(RecommendationEngine::class)->recommend(new RecommendationContext(RecommendationSource::Search, [
            'snapshot' => $state, 'bottleneck' => null, 'strategies' => [], 'configsBySectionId' => [$f['section']->id => $configuration],
        ]))->legacyPayload;
        $id = 'session-enhancement-'.$f['section']->id.'-'.$f['course']->id;
        $option = collect($recommend($snapshot, $config))->firstWhere('id', $id);
        if (! $expected) {
            $this->assertNull($option);

            return;
        }
        $this->assertNotNull($option);
        $this->assertEqualsCanonicalizing($days, array_column($option['group_witness'], 'day'));
        $this->assertSame(['online', 'online'], array_column($option['group_witness'], 'mode'));
        $this->assertSame(['07:00:00', '07:00:00'], array_column($option['group_witness'], 'start_time'));
        $this->assertSame(['08:30:00', '08:30:00'], array_column($option['group_witness'], 'end_time'));
        $this->assertDatabaseCount('schedules', 7);
        $forced = $snapshot->toArray();
        $forced['forced_days_by_course_id'] = [$f['course']->id => $days[0]];
        $this->assertNull(collect($recommend(SchedulingSnapshot::fromArray($forced), $config))->firstWhere('id', $id));
        unset($config['allowed_days']);
        $this->assertNull(collect($recommend($snapshot, $config))->firstWhere('id', $id), 'Unrestricted generation keeps the standard pairs.');
        $this->save($f, $option['group_witness'])->assertSuccessful();
    }

    public static function enhancementFallbackDays(): array
    {
        return [
            'spaced' => [['Monday', 'Thursday'], false, true],
            'adjacent' => [['Monday', 'Tuesday'], false, true],
            'Friday Saturday fallback' => [['Friday', 'Saturday'], false, true],
            'Sunday enabled' => [['Saturday', 'Sunday'], true, true],
            'Sunday disabled' => [['Saturday', 'Sunday'], false, false],
            'one day' => [['Monday'], false, false],
        ];
    }

    private function catalog(array $f, array $rows): array
    {
        $rows = array_map(fn (array $row): array => $this->identified($f, $row), $rows);
        $provider = app(PlacementRecommendationProvider::class);
        $snapshot = app(SchedulingSnapshotRepository::class)->capture($f['semester']->id, $f['department']->id,
            [$f['section']->id], [$f['course']->id], replacedClasses: [[$f['section']->id, $f['course']->id]]);

        return $provider->groupOptions($snapshot, [
            'section_id' => $f['section']->id, 'course_id' => $f['course']->id,
            'shape' => SessionInterpreter::fromRows($rows)->legacyShape, 'replaces' => $rows, 'keeps' => [],
            'meetings' => array_map($provider->meetingFromRow(...), $rows),
        ], $rows, null);
    }

    private function manual(array $f, array $rows, array $extra = [])
    {
        return $this->actingAs($f['user'])->postJson('/api/schedule-recommendations/available-slots', [
            'section_id' => $f['section']->id, 'course_id' => $f['course']->id,
            'duration_slots' => 3, 'meeting_type' => $rows[0]['meeting_type'], 'modes' => ['on-site', 'online'],
            'placement' => ['rows' => $rows, 'selected_meeting' => 0], ...$extra,
        ]);
    }

    private function save(array $f, array $rows)
    {
        return $this->actingAs($f['user'])->postJson('/api/schedules/batch', ['operations' => array_map(static fn (array $row): array => [
            ...$row, 'start_time' => substr($row['start_time'], 0, 5), 'end_time' => substr($row['end_time'], 0, 5),
        ], $rows)]);
    }

    private function pair(array $f): array
    {
        return array_map(static fn (string $day): array => ['day' => $day, 'start_time' => '08:00', 'end_time' => '09:30',
            'mode' => 'on-site', 'room_id' => $f['room']->id, 'faculty_id' => null, 'is_hybrid' => false,
            'preferred_pattern' => 'MW', 'meeting_type' => 'lecture'], ['Monday', 'Wednesday']);
    }

    private function identified(array $f, array $row): array
    {
        return [...$row, 'semester_id' => $f['semester']->id, 'department_id' => $f['department']->id,
            'section_id' => $f['section']->id, 'course_id' => $f['course']->id, 'split_group_id' => 'test-group', 'status' => 'draft'];
    }

    private function booking(array $f, string $day, string $start, string $end): Schedule
    {
        return Schedule::create([...$this->identified($f, []), 'course_id' => $f['other']->id, 'room_id' => $f['room']->id,
            'day' => $day, 'start_time' => $start, 'end_time' => $end, 'mode' => 'on-site']);
    }

    private function fixture(): array
    {
        $semester = Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
        $department = Departments::create(['department_name' => 'Information Technology', 'department_code' => 'IT']);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSIT', 'name' => 'BS Information Technology']);
        $curriculum = Curriculum::create(['name' => 'IT', 'code' => 'IT-2026', 'department_id' => $department->id, 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $section = Sections::create(['section_name' => 'IT 1A', 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active']);
        $courses = [];
        foreach (['GEC 101', 'GEC 102'] as $code) {
            $course = Course::create(['course_code' => $code, 'course_name' => $code, 'lecture_hours' => 3, 'lab_hours' => 0,
                'units' => 3, 'course_category' => 'minor', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st', 'status' => 'active']);
            $curriculum->courses()->attach($course->id, ['year_level' => 1, 'semester' => 1]);
            $courses[] = $course;
        }
        $room = Rooms::create(['room_code' => 'IT 101', 'building' => 'IT', 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
        $user = $this->grantCapabilities(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));

        return compact('semester', 'department', 'section', 'room', 'user') + ['course' => $courses[0], 'other' => $courses[1]];
    }
}
