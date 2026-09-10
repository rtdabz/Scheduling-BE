<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Solver;

use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\ScheduleRow;
use App\Services\Scheduling\Domain\SchedulingSnapshot;
use App\Services\Scheduling\SchedulingPolicy;
use InvalidArgumentException;

final class CspCandidateRowMapper
{
    /** @return list<ScheduleRow> */
    public function map(
        array $candidate,
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): array {
        $section = $snapshot->sectionsById[$configuration->sectionId] ?? null;
        $courseId = (int) ($candidate['course_id'] ?? 0);
        $course = $snapshot->coursesById[$courseId] ?? null;
        $blocks = array_values(is_array($candidate['blocks'] ?? null) ? $candidate['blocks'] : []);

        if (! is_array($section) || ! is_array($course) || $courseId <= 0 || $blocks === []) {
            throw new InvalidArgumentException('A CSP candidate cannot be mapped without its section, course, and blocks.');
        }

        $hasMultipleBlocks = count($blocks) > 1;
        $splitGroupId = $hasMultipleBlocks
            ? sprintf('domain-%d-%d', $configuration->sectionId, $courseId)
            : null;
        $rows = [];

        foreach ($blocks as $index => $block) {
            $mode = (string) ($block['mode'] ?? $candidate['mode'] ?? $configuration->deliveryMode);
            $roomId = $this->positiveIntOrNull(
                array_key_exists('room_id', $block) ? $block['room_id'] : ($candidate['room_id'] ?? null),
            );

            $rows[] = new ScheduleRow(
                termId: $snapshot->termId,
                sectionId: $configuration->sectionId,
                courseId: $courseId,
                departmentId: $snapshot->departmentId,
                day: (string) ($block['day'] ?? ''),
                startTime: SchedulingPolicy::normalizeTime((string) ($block['start_time'] ?? '')),
                endTime: SchedulingPolicy::normalizeTime((string) ($block['end_time'] ?? '')),
                mode: $mode,
                facultyId: $this->positiveIntOrNull($candidate['faculty_id'] ?? null),
                roomId: $roomId,
                isHybrid: (bool) ($candidate['is_hybrid'] ?? $configuration->isHybrid),
                isHybrid: (bool) ($candidate['is_hybrid'] ?? false),
                preferredPattern: $this->nullableString($candidate['preferred_pattern'] ?? null),
                splitGroupId: $splitGroupId,
                meetingType: $this->meetingType($block, $course, $hasMultipleBlocks, $index),
                meetingIndex: $hasMultipleBlocks ? $index + 1 : null,
            );
        }

        return $rows;
    }

    private function meetingType(array $block, array $course, bool $hasMultipleBlocks, int $index): ?string
    {
        $explicit = $this->nullableString($block['meeting_type'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        if (! $hasMultipleBlocks) {
            return null;
        }

        $durationMinutes = SchedulingPolicy::timeToMinutes((string) $block['end_time'])
            - SchedulingPolicy::timeToMinutes((string) $block['start_time']);
        if ((int) ($course['lab_hours'] ?? 0) > 0
            && $durationMinutes === (int) $course['lab_hours'] * 180) {
            return 'laboratory';
        }
        if ((int) ($course['lecture_hours'] ?? 0) > 0
            && $durationMinutes === (int) $course['lecture_hours'] * 60) {
            return 'lecture';
        }

        return $index === 0 ? 'lecture' : 'laboratory';
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
