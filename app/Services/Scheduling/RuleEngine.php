<?php

namespace App\Services\Scheduling;

use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Subjects;
use App\Models\Terms;
use InvalidArgumentException;

class RuleEngine
{
    public function checkRoomConflict(
        int $roomId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflict = Schedule::where('room_id', $roomId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['subject', 'section'])
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule' => 'room_conflict',
            'message' => "Room is already booked on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->subject?->subject_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    public function checkFacultyConflict(
        int $facultyId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflict = Schedule::where('faculty_id', $facultyId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['subject', 'section'])
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule' => 'faculty_conflict',
            'message' => "Faculty is already teaching on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."for {$conflict->subject?->subject_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    public function checkSectionConflict(
        int $sectionId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        int|array|null $ignoreScheduleId = null
    ): ?array {
        $ignoreScheduleIds = $this->normalizeIgnoreScheduleIds($ignoreScheduleId);

        $conflict = Schedule::where('section_id', $sectionId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreScheduleIds))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with('subject')
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule' => 'section_conflict',
            'message' => "Section already has a class on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                ."({$conflict->subject?->subject_code}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    public function checkPreferredPattern(string $day, ?string $preferredPattern): ?array
    {
        if (empty($preferredPattern)) {
            return null;
        }

        try {
            $allowedDays = SchedulingPolicy::allowedDaysForPattern($preferredPattern);
        } catch (InvalidArgumentException $exception) {
            return [
                'rule' => 'preferred_pattern',
                'message' => $exception->getMessage(),
            ];
        }

        if ($allowedDays !== null && !in_array($day, $allowedDays, true)) {
            return [
                'rule' => 'preferred_pattern',
                'message' => "Preferred pattern conflict: '{$preferredPattern}' subjects can only be scheduled on "
                    .implode(' or ', $allowedDays).", not {$day}.",
            ];
        }

        return null;
    }

    public function checkOperatingHours(string $startTime, string $endTime): ?array
    {
        $start = SchedulingPolicy::normalizeTime($startTime);
        $end = SchedulingPolicy::normalizeTime($endTime);

        if ($start < SchedulingPolicy::openingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule starts at {$startTime}, which is before operating hours begin (7:00 AM).",
            ];
        }

        if ($end > SchedulingPolicy::closingTime()) {
            return [
                'rule' => 'operating_hours',
                'message' => "Schedule ends at {$endTime}, which exceeds operating hours (9:00 PM cutoff).",
            ];
        }

        return null;
    }

    public function checkRoomTypeMatch(int $subjectId, int $roomId): ?array
    {
        $subject = Subjects::find($subjectId);
        $room = Rooms::find($roomId);

        if (!$subject || !$room) {
            return [
                'rule' => 'room_type_match',
                'message' => 'Subject or room not found for room-type validation.',
            ];
        }

        if ($subject->room_type_required !== $room->room_type) {
            return [
                'rule' => 'room_type_match',
                'message' => "Subject {$subject->subject_code} requires a '{$subject->room_type_required}' room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        return null;
    }

    public function checkTimeSlotGrid(string $startTime, string $endTime): ?array
    {
        $startMinutes = $this->timeToMinutes($startTime);
        $endMinutes = $this->timeToMinutes($endTime);

        if ($startMinutes === null || $endMinutes === null) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule start and end times must be valid time values.',
            ];
        }

        if ($endMinutes <= $startMinutes) {
            return [
                'rule' => 'operating_hours',
                'message' => 'Schedule end time must be after start time.',
            ];
        }

        if (
            ($startMinutes - SchedulingPolicy::OPERATING_START_MINUTES) % SchedulingPolicy::SLOT_MINUTES !== 0
            || ($endMinutes - SchedulingPolicy::OPERATING_START_MINUTES) % SchedulingPolicy::SLOT_MINUTES !== 0
        ) {
            return [
                'rule' => 'slot_grid',
                'message' => 'Schedule times must align to 30-minute scheduling slots.',
            ];
        }

        return null;
    }

    public function checkRelationalIntegrity(array $attempt): array
    {
        $violations = [];

        $term = Terms::find($attempt['term_id']);
        $section = Sections::find($attempt['section_id']);
        $subject = Subjects::find($attempt['subject_id']);
        $room = Rooms::find($attempt['room_id']);
        $faculty = !empty($attempt['faculty_id'])
            ? Faculty::find($attempt['faculty_id'])
            : null;

        if (!$term) {
            $violations[] = [
                'rule' => 'term_exists',
                'message' => 'Selected academic term does not exist.',
            ];
        }

        if (!$section) {
            $violations[] = [
                'rule' => 'section_exists',
                'message' => 'Selected section does not exist.',
            ];
        }

        if (!$subject) {
            $violations[] = [
                'rule' => 'subject_exists',
                'message' => 'Selected subject does not exist.',
            ];
        }

        if (!$room) {
            $violations[] = [
                'rule' => 'room_exists',
                'message' => 'Selected room does not exist.',
            ];
        }

        if (!empty($attempt['faculty_id']) && !$faculty) {
            $violations[] = [
                'rule' => 'faculty_exists',
                'message' => 'Selected faculty member does not exist.',
            ];
        }

        if (!$term || !$section || !$subject || !$room || (!empty($attempt['faculty_id']) && !$faculty)) {
            return $violations;
        }

        if (!(bool) ($term->is_enabled ?? true)) {
            $violations[] = [
                'rule' => 'term_enabled',
                'message' => 'Selected academic term is disabled for scheduling.',
            ];
        }

        if ((int) $section->term_id !== (int) $term->id) {
            $violations[] = [
                'rule' => 'section_term_alignment',
                'message' => 'Selected section does not belong to the selected academic term.',
            ];
        }

        if ((string) $section->semester !== (string) $term->semester) {
            $violations[] = [
                'rule' => 'section_term_semester_alignment',
                'message' => 'Section semester does not match its academic term semester.',
            ];
        }

        if ((string) $subject->semester !== (string) $section->semester) {
            $violations[] = [
                'rule' => 'subject_section_semester_alignment',
                'message' => 'Subject semester does not match the selected section semester.',
            ];
        }

        if ((string) $subject->year_level !== (string) $section->year_level) {
            $violations[] = [
                'rule' => 'subject_section_year_alignment',
                'message' => 'Subject year level does not match the selected section year level.',
            ];
        }

        $attemptDepartmentId = $attempt['department_id'] ?? $section->department_id;
        if ((int) $attemptDepartmentId !== (int) $section->department_id) {
            $violations[] = [
                'rule' => 'schedule_department_alignment',
                'message' => 'Schedule department must match the selected section department.',
            ];
        }

        if (($section->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'section_active',
                'message' => 'Selected section is inactive and cannot be scheduled.',
            ];
        }

        if (($subject->status ?? 'active') !== 'active') {
            $violations[] = [
                'rule' => 'subject_active',
                'message' => 'Selected subject is inactive and cannot be scheduled.',
            ];
        }

        if (($room->status ?? 'available') !== 'available') {
            $violations[] = [
                'rule' => 'room_availability',
                'message' => "Room {$room->room_code} is not available for scheduling.",
            ];
        }

        if ($room->department_id !== null && (int) $room->department_id !== (int) $section->department_id) {
            $violations[] = [
                'rule' => 'room_department_alignment',
                'message' => 'Selected room is not shared and does not belong to the selected section department.',
            ];
        }

        if (
            $subject->subject_category === 'major'
            && $subject->department_id !== null
            && (int) $subject->department_id !== (int) $section->department_id
        ) {
            $violations[] = [
                'rule' => 'major_department_alignment',
                'message' => 'Major subject department does not match the selected section department.',
            ];
        }

        if ($faculty) {
            if (($faculty->status ?? 'active') !== 'active') {
                $violations[] = [
                    'rule' => 'faculty_active',
                    'message' => 'Selected faculty member is inactive and cannot be assigned.',
                ];
            }
        }

        $mode = (string) ($attempt['mode'] ?? 'on-site');
        if ($mode === 'online' && $room->room_type !== 'online') {
            $violations[] = [
                'rule' => 'delivery_room_alignment',
                'message' => 'Online schedules must use an online room assignment.',
            ];
        }

        if ($mode === 'field' && $room->room_type !== 'field') {
            $violations[] = [
                'rule' => 'delivery_room_alignment',
                'message' => 'Field schedules must use a field room assignment.',
            ];
        }

        if ($mode === 'on-site' && in_array($room->room_type, ['online', 'field'], true)) {
            $violations[] = [
                'rule' => 'delivery_room_alignment',
                'message' => 'On-site schedules must use a lecture or laboratory room assignment.',
            ];
        }

        return $violations;
    }

    public function validate(array $attempt): array
    {
        $violations = [];
        $ignoreId = $attempt['ignore_schedule_id'] ?? null;

        if (!in_array($attempt['day'], SchedulingPolicy::PERSISTABLE_DAYS, true)) {
            $violations[] = [
                'rule' => 'valid_day',
                'message' => "Unsupported schedule day '{$attempt['day']}'.",
            ];
        }

        if (
            isset($attempt['mode'])
            && !SchedulingPolicy::isValidDeliveryMode((string) $attempt['mode'])
        ) {
            $violations[] = [
                'rule' => 'delivery_mode',
                'message' => "Unsupported delivery mode '{$attempt['mode']}'.",
            ];
        }

        if (($attempt['mode'] ?? null) === 'field' && !empty($attempt['is_hybrid'])) {
            $violations[] = [
                'rule' => 'hybrid_mode',
                'message' => 'Field schedules cannot be marked as hybrid.',
            ];
        }

        $slotGrid = $this->checkTimeSlotGrid(
            $attempt['start_time'],
            $attempt['end_time']
        );
        if ($slotGrid) {
            $violations[] = $slotGrid;
        }

        $violations = array_merge(
            $violations,
            $this->checkRelationalIntegrity($attempt)
        );

        $roomConflict = $this->checkRoomConflict(
            $attempt['room_id'],
            $attempt['term_id'],
            $attempt['day'],
            $attempt['start_time'],
            $attempt['end_time'],
            $ignoreId
        );
        if ($roomConflict) {
            $violations[] = $roomConflict;
        }

        if (!empty($attempt['faculty_id'])) {
            $facultyConflict = $this->checkFacultyConflict(
                $attempt['faculty_id'],
                $attempt['term_id'],
                $attempt['day'],
                $attempt['start_time'],
                $attempt['end_time'],
                $ignoreId
            );
            if ($facultyConflict) {
                $violations[] = $facultyConflict;
            }
        }

        $sectionConflict = $this->checkSectionConflict(
            $attempt['section_id'],
            $attempt['term_id'],
            $attempt['day'],
            $attempt['start_time'],
            $attempt['end_time'],
            $ignoreId
        );
        if ($sectionConflict) {
            $violations[] = $sectionConflict;
        }

        $roomTypeMatch = $this->checkRoomTypeMatch(
            $attempt['subject_id'],
            $attempt['room_id']
        );
        if ($roomTypeMatch) {
            $violations[] = $roomTypeMatch;
        }

        $patternCheck = $this->checkPreferredPattern(
            $attempt['day'],
            $attempt['preferred_pattern'] ?? null
        );
        if ($patternCheck) {
            $violations[] = $patternCheck;
        }

        $hoursCheck = $this->checkOperatingHours(
            $attempt['start_time'],
            $attempt['end_time']
        );
        if ($hoursCheck) {
            $violations[] = $hoursCheck;
        }

        return $violations;
    }

    private function timeToMinutes(string $time): ?int
    {
        $parts = explode(':', SchedulingPolicy::normalizeTime($time));

        if (count($parts) < 2) {
            return null;
        }

        if (!ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    private function normalizeIgnoreScheduleIds(int|array|null $ignoreScheduleId): array
    {
        if ($ignoreScheduleId === null) {
            return [];
        }

        $ids = is_array($ignoreScheduleId) ? $ignoreScheduleId : [$ignoreScheduleId];

        return array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        ));
    }
}
