<?php

namespace App\Services\Scheduling\History;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A read-only JSON snapshot of one VPAA-approved history entry, for inspection and documentation.
 *
 * Built from the recorded history items (the schedule as approved) and enriched with the related
 * records still on file. It is never an input: Schedule Reuse reads the database, not this file.
 */
final class ScheduleHistoryExporter
{
    public const SCHEMA = 'wicars.schedule-export';

    public const SCHEMA_VERSION = '1.0';

    /** @return array<string, mixed> */
    public function export(ScheduleHistoryVersion $version, ?User $exporter): array
    {
        $rows = ApprovedScheduleHistory::rows($version);
        $snapshots = $rows->pluck('snapshot');
        $courses = Course::withTrashed()->whereKey($snapshots->pluck('course_id')->filter()->unique())->get()->keyBy('id');
        $rooms = Rooms::withTrashed()->whereKey($snapshots->pluck('room_id')->filter()->unique())->get()->keyBy('id');
        $sections = Sections::query()->whereKey($snapshots->pluck('section_id')->filter()->unique())->get()->keyBy('id');
        $programs = Program::query()->whereKey($snapshots->pluck('program_id')->merge($sections->pluck('program_id'))->filter()->unique())->get()->keyBy('id');
        $curricula = Curriculum::query()->whereKey($snapshots->pluck('curriculum_id')->merge($sections->pluck('curriculum_id'))->filter()->unique())->get()->keyBy('id');

        $classes = $rows->map(fn (array $row) => $this->classRow($row, $courses, $rooms));
        $sectionRows = $classes->groupBy('section_id')->map(function (Collection $sectionClasses, $sectionId) use ($rows, $sections, $programs) {
            $row = $rows->first(fn ($row) => (int) ($row['snapshot']['section_id'] ?? 0) === (int) $sectionId);
            $section = $sections->get((int) $sectionId);
            $programId = $row['snapshot']['program_id'] ?? $section?->program_id;
            $program = $programId === null ? null : $programs->get((int) $programId);

            return [
                'section_id' => $sectionId === '' ? null : (int) $sectionId,
                'name' => $row['metadata']['section_name'] ?? $section?->section_name,
                'year_level' => $this->intOrNull($row['metadata']['section_year_level'] ?? $section?->year_level),
                'program' => $program === null ? null : ['id' => (int) $program->id, 'code' => $program->code, 'name' => $program->name],
                'curriculum_id' => $this->intOrNull($row['snapshot']['curriculum_id'] ?? $section?->curriculum_id),
                'class_count' => $sectionClasses->count(),
                'classes' => $sectionClasses->map(fn (array $class) => collect($class)->except('section_id')->all())->values()->all(),
            ];
        })->sortBy('name')->values();

        $first = $rows->first();
        $approval = $this->approval($version, $sections->keys()->all());

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'reference_data_policy' => 'Actor and instructor identities use recorded snapshots only. Missing course, room, program and curriculum labels may be enriched from current master records.',
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $exporter === null ? null : ['id' => (int) $exporter->id, 'name' => $exporter->name, 'role' => $exporter->role],
            'schedule' => [
                'history_entry_id' => (int) $version->id,
                'event' => $version->action,
                'event_label' => $version->action === ApprovedScheduleHistory::VPAA_APPROVED ? 'Approved by VPAA' : 'Semester snapshot',
                'recorded_at' => $version->created_at?->toIso8601String(),
                'original_schedule_ids' => $rows->pluck('original_schedule_id')->filter()->values()->all(),
                'class_count' => $rows->count(),
                'section_count' => $sectionRows->count(),
            ],
            'approval' => $approval,
            'department' => [
                'id' => $this->intOrNull($version->department_id),
                'name' => $version->department?->department_name ?? ($first['metadata']['department_name'] ?? null),
                'code' => $version->department?->department_code ?? ($first['metadata']['department_code'] ?? null),
            ],
            'academic_period' => [
                'semester_id' => $this->intOrNull($version->semester_id),
                'academic_year' => ApprovedScheduleHistory::academicYear($version),
                'semester' => ApprovedScheduleHistory::term($version),
            ],
            'curricula' => $curricula->map(fn (Curriculum $curriculum) => [
                'id' => (int) $curriculum->id,
                'code' => $curriculum->code,
                'name' => $curriculum->name,
                'effective_school_year' => $curriculum->effective_school_year,
                'program_id' => $this->intOrNull($curriculum->program_id),
                'current_status' => $curriculum->status,
            ])->values()->all(),
            'sections' => $sectionRows->all(),
            'instructors' => $classes->whereNotNull('instructor')->groupBy('instructor.id')->map(fn (Collection $group) => [
                ...$group->first()['instructor'],
                'class_count' => $group->count(),
            ])->values()->all(),
            'rooms' => $classes->whereNotNull('room')->groupBy('room.id')->map(fn (Collection $group) => [
                ...$group->first()['room'],
                'class_count' => $group->count(),
            ])->values()->all(),
        ];
    }

    public function filename(ScheduleHistoryVersion $version): string
    {
        return Str::slug(implode('-', array_filter([
            'wicars-schedule',
            $version->department?->department_code,
            ApprovedScheduleHistory::academicYear($version),
            ApprovedScheduleHistory::term($version),
            'entry-'.$version->id,
        ]))).'.json';
    }

    /** @return array<string, mixed> */
    private function classRow(array $row, Collection $courses, Collection $rooms): array
    {
        $snapshot = $row['snapshot'];
        $metadata = $row['metadata'];
        $course = $courses->get((int) ($snapshot['course_id'] ?? 0));
        $room = isset($snapshot['room_id']) ? $rooms->get((int) $snapshot['room_id']) : null;
        $lectureHours = (float) ($metadata['lecture_hours'] ?? $course?->lecture_hours ?? 0);
        $labHours = (float) ($metadata['lab_hours'] ?? $course?->lab_hours ?? 0);

        return [
            'section_id' => $this->intOrNull($snapshot['section_id'] ?? null) ?? '',
            'schedule_id' => $row['original_schedule_id'],
            'course' => [
                'id' => $this->intOrNull($snapshot['course_id'] ?? null),
                'code' => $metadata['course_code'] ?? $course?->course_code,
                'name' => $metadata['course_name'] ?? $course?->course_name,
                'category' => $metadata['course_category'] ?? $course?->course_category,
                'units' => $this->numberOrNull($metadata['units'] ?? $course?->units),
                'lecture_hours' => $lectureHours,
                'lab_hours' => $labHours,
            ],
            'instructor' => isset($snapshot['faculty_id']) ? [
                'id' => (int) $snapshot['faculty_id'],
                'name' => $metadata['faculty_name'] ?? null,
                'identity_source' => isset($metadata['faculty_name']) ? 'recorded_snapshot' : 'unavailable',
            ] : null,
            'room' => isset($snapshot['room_id']) ? [
                'id' => (int) $snapshot['room_id'],
                'code' => $metadata['room_name'] ?? $room?->room_code,
                'building' => $room?->building,
                'room_type' => $room?->room_type,
            ] : null,
            'day' => $snapshot['day'] ?? null,
            'start_time' => substr((string) ($snapshot['start_time'] ?? ''), 0, 5) ?: null,
            'end_time' => substr((string) ($snapshot['end_time'] ?? ''), 0, 5) ?: null,
            'delivery_mode' => $snapshot['mode'] ?? 'on-site',
            'is_hybrid' => (bool) ($snapshot['is_hybrid'] ?? false),
            'class_type' => $snapshot['meeting_type'] ?? match (true) {
                $labHours > 0 && $lectureHours <= 0 => 'laboratory',
                $labHours > 0 => 'lecture_and_laboratory',
                default => 'lecture',
            },
            'meeting_index' => $this->intOrNull($snapshot['meeting_index'] ?? null),
            'split_group_id' => $snapshot['split_group_id'] ?? null,
            'preferred_pattern' => $snapshot['preferred_pattern'] ?? null,
            'status_when_recorded' => $snapshot['status'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function approval(ScheduleHistoryVersion $version, array $sectionIds): array
    {
        $submissionId = $version->action === ApprovedScheduleHistory::VPAA_APPROVED
            ? SchedulingAuditLog::query()->where('history_version_id', $version->id)->value('schedule_submission_id')
            : null;
        $submission = $submissionId !== null ? ScheduleSubmission::query()->find($submissionId) : null;
        $approver = $version->action === ApprovedScheduleHistory::VPAA_APPROVED ? $version->recorded_actor : null;

        return [
            'status' => 'approved_by_vpaa',
            'approved_at' => ($submission?->vpaa_reviewed_at ?? ($version->action === ApprovedScheduleHistory::VPAA_APPROVED ? $version->created_at : null))?->toIso8601String(),
            'approved_by' => $approver,
            'schedule_submission_id' => $submission === null ? null : (int) $submission->id,
            'revision_number' => $submission?->revision_number === null ? null : (int) $submission->revision_number,
            'signatories' => $version->signatories,
            'section_signatories' => ApprovedScheduleHistory::rows($version)->map(fn (array $row) => [
                'section_id' => $row['snapshot']['section_id'] ?? null,
                'signatories' => $row['snapshot']['signatories'] ?? $version->signatories,
            ])->unique('section_id')->values()->all(),
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        return $value === null || $value === '' ? null : $value + 0;
    }
}
