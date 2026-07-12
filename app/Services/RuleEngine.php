<?php

namespace App\Services\Scheduling;

use App\Models\Schedule;
use App\Models\Rooms;
use App\Models\Subjects;

class RuleEngine
{
    /**
     * Check if a room is already booked for the same term, day, and overlapping time.
     *
     * @param  int         $roomId
     * @param  int         $termId
     * @param  string      $day
     * @param  string      $startTime   'H:i' or 'H:i:s'
     * @param  string      $endTime     'H:i' or 'H:i:s'
     * @param  int|null    $ignoreScheduleId  exclude this schedule (used when updating an existing slot)
     * @return array|null  null if no conflict, otherwise a violation array
     */
    public function checkRoomConflict(
        int $roomId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        ?int $ignoreScheduleId = null
    ): ?array {
        $conflict = Schedule::where('room_id', $roomId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['subject', 'section'])
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule'    => 'room_conflict',
            'message' => "Room is already booked on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                       . "for {$conflict->subject?->subject_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    /**
     * Check if a faculty member is already assigned to another class for the same term,
     * day, and overlapping time.
     *
     * @param  int         $facultyId
     * @param  int         $termId
     * @param  string      $day
     * @param  string      $startTime
     * @param  string      $endTime
     * @param  int|null    $ignoreScheduleId
     * @return array|null
     */
    public function checkFacultyConflict(
        int $facultyId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        ?int $ignoreScheduleId = null
    ): ?array {
        $conflict = Schedule::where('faculty_id', $facultyId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with(['subject', 'section'])
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule'    => 'faculty_conflict',
            'message' => "Faculty is already teaching on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                       . "for {$conflict->subject?->subject_code} ({$conflict->section?->section_name}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    /**
     * Check if a section already has another class for the same term, day, and
     * overlapping time (a section cannot attend two classes at once).
     *
     * @param  int         $sectionId
     * @param  int         $termId
     * @param  string      $day
     * @param  string      $startTime
     * @param  string      $endTime
     * @param  int|null    $ignoreScheduleId
     * @return array|null
     */
    public function checkSectionConflict(
        int $sectionId,
        int $termId,
        string $day,
        string $startTime,
        string $endTime,
        ?int $ignoreScheduleId = null
    ): ?array {
        $conflict = Schedule::where('section_id', $sectionId)
            ->where('term_id', $termId)
            ->where('day', $day)
            ->when($ignoreScheduleId, fn ($q) => $q->where('id', '!=', $ignoreScheduleId))
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->with('subject')
            ->first();

        if (!$conflict) {
            return null;
        }

        return [
            'rule'    => 'section_conflict',
            'message' => "Section already has a class on {$day} from {$conflict->start_time} to {$conflict->end_time} "
                       . "({$conflict->subject?->subject_code}).",
            'conflicting_schedule_id' => $conflict->id,
        ];
    }

    /**
     * Check that the assigned room's type matches what the subject requires
     * (e.g. a laboratory subject must be placed in a laboratory room).
     *
     * @param  int    $subjectId
     * @param  int    $roomId
     * @return array|null
     */
    public function checkRoomTypeMatch(int $subjectId, int $roomId): ?array
    {
        $subject = Subjects::find($subjectId);
        $room    = Rooms::find($roomId);

        if (!$subject || !$room) {
            return [
                'rule'    => 'room_type_match',
                'message' => 'Subject or room not found for room-type validation.',
            ];
        }

        if ($subject->room_type_required !== $room->room_type) {
            return [
                'rule'    => 'room_type_match',
                'message' => "Subject {$subject->subject_code} requires a '{$subject->room_type_required}' room, "
                           . "but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        return null;
    }

    /**
     * Run all applicable checks for a single proposed schedule slot and
     * return every violation found (empty array = fully valid).
     *
     * Expected $attempt keys:
     *   term_id, section_id, subject_id, faculty_id (nullable), room_id,
     *   day, start_time, end_time, ignore_schedule_id (optional)
     *
     * @param  array $attempt
     * @return array list of violation arrays; empty means no conflicts
     */
    public function validate(array $attempt): array
    {
        $violations = [];

        $ignoreId = $attempt['ignore_schedule_id'] ?? null;

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

        // Faculty may be null at the scheduling (pre-assignment) phase — only
        // check if a faculty has actually been assigned to this slot.
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

        return $violations;
    }
}