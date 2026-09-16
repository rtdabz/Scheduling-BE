<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * semester_exists, section_exists, subject_exists, room_exists, faculty_exists,
 * section_active, subject_active.
 *
 * Resolves the records an attempt points at. When one is missing it reports
 * that and returns no records, because every later record-based rule needs them.
 */
final class ReferenceIntegrityRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * @param  array<string, mixed>  $attempt
     * @return array{violations: list<array<string, mixed>>, records: AttemptRecords|null}
     */
    public function check(array $attempt): array
    {
        $violations = [];

        $semester = $this->lookups->remember('semester:'.$attempt['semester_id'], fn () => Semester::find($attempt['semester_id']));
        $section = $this->lookups->remember('section:'.$attempt['section_id'], fn () => Sections::find($attempt['section_id']));
        $courseId = $attempt['course_id'] ?? $attempt['subject_id'] ?? null;
        $course = $courseId ? $this->lookups->remember('course:'.$courseId, fn () => Course::find($courseId)) : null;
        $mode = (string) ($attempt['mode'] ?? 'on-site');
        $roomId = $attempt['room_id'] ?? null;
        $room = $roomId !== null ? $this->lookups->remember('room:'.$roomId, fn () => Rooms::find($roomId)) : null;
        $allowsLabTba = $course !== null
            && $mode === 'on-site'
            && $room === null
            && SchedulingPolicy::allowsRoomTbaFallback(
                $course,
                $section?->department_id === null ? null : (int) $section->department_id,
                $attempt['meeting_type'] ?? null,
            );
        $faculty = ! empty($attempt['faculty_id'])
            ? $this->lookups->remember('faculty:'.$attempt['faculty_id'], fn () => Faculty::find($attempt['faculty_id']))
            : null;

        if (! $semester) {
            $violations[] = [
                'rule' => 'semester_exists',
                'message' => 'Selected academic semester does not exist.',
            ];
        }

        if (! $section) {
            $violations[] = [
                'rule' => 'section_exists',
                'message' => 'Selected section does not exist.',
            ];
        }

        if (! $course) {
            $violations[] = [
                'rule' => 'subject_exists',
                'message' => 'Selected course does not exist.',
            ];
        }

        // No room chosen at all is room_type_match's "a physical room is required";
        // this rule is only for a room ID that points at nothing.
        if ($roomId !== null && ! $room && $mode !== 'online' && ! $allowsLabTba) {
            $violations[] = [
                'rule' => 'room_exists',
                'message' => 'Selected room does not exist.',
            ];
        }

        if (! empty($attempt['faculty_id']) && ! $faculty) {
            $violations[] = [
                'rule' => 'faculty_exists',
                'message' => 'Selected faculty member does not exist.',
            ];
        }

        if (! $semester || ! $section || ! $course || (! $room && $mode !== 'online' && ! $allowsLabTba) || (! empty($attempt['faculty_id']) && ! $faculty)) {
            return ['violations' => $violations, 'records' => null];
        }

        if (($section->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'section_active',
                'message' => 'Selected section is inactive and cannot be scheduled.',
            ];
        }

        if (($course->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'subject_active',
                'message' => 'Selected course is inactive and cannot be scheduled.',
            ];
        }

        return [
            'violations' => $violations,
            'records' => new AttemptRecords($semester, $section, $course, $room, $faculty, $mode),
        ];
    }
}
