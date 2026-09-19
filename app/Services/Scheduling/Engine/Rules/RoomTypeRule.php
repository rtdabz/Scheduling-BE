<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Rooms;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * room_type_match: the delivery mode and the assigned room suit what the course
 * (or this component of it) requires — a laboratory, a lecture room, a field,
 * or online delivery where that is an allowed fallback.
 */
final class RoomTypeRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * $departmentId is the scheduling department, not the course's owner. Field
     * course codes are configured per department and shared minors carry no
     * department of their own, so the room-type rule must be asked in the same
     * terms as the day rules or one course gets two answers in one run.
     *
     * @return array<string, mixed>|null
     */
    public function check(
        int $courseId,
        ?int $roomId,
        string $deliveryMode = 'on-site',
        ?string $meetingType = null,
        ?int $departmentId = null,
    ): ?array {
        $course = $this->lookups->remember('course:'.$courseId, fn () => Course::find($courseId));

        if (! $course) {
            return [
                'rule' => 'room_type_match',
                'message' => 'Course not found for room-type validation.',
            ];
        }

        $room = $roomId !== null ? $this->lookups->remember('room:'.$roomId, fn () => Rooms::find($roomId)) : null;

        return self::mismatch($course, $room, $deliveryMode, $meetingType, $departmentId);
    }

    /**
     * The room-type decision itself, for a course and room in either form: the
     * models RuleEngine loads, or the constraint kernel's snapshot arrays. The
     * kernel passes its snapshot's field-course codes so no database lookup
     * happens; RuleEngine passes none and the department's list is read.
     *
     * @param  Course|array<string, mixed>  $course
     * @param  Rooms|array<string, mixed>|null  $room
     * @param  list<string>|null  $fieldCourseCodes
     * @return array{rule: string, message: string}|null
     */
    public static function mismatch(
        Course|array $course,
        Rooms|array|null $room,
        string $deliveryMode,
        ?string $meetingType,
        ?int $departmentId,
        ?array $fieldCourseCodes = null,
    ): ?array {
        $courseCode = (string) (is_array($course) ? ($course['course_code'] ?? '') : $course->course_code);
        $roomType = $room === null ? null : (string) (is_array($room) ? ($room['room_type'] ?? '') : $room->room_type);
        $roomCode = $room === null ? null : (string) (is_array($room) ? ($room['room_code'] ?? '') : $room->room_code);
        $requiredRoomType = SchedulingPolicy::effectiveRoomType($course, $departmentId, $meetingType, $fieldCourseCodes);
        $violation = static fn (string $message): array => ['rule' => 'room_type_match', 'message' => $message];

        if ($deliveryMode === 'online') {
            return SchedulingPolicy::allowsOnlineRoomFallback($course, $departmentId, $meetingType, $fieldCourseCodes)
                ? null
                : $violation("Course {$courseCode} cannot use online delivery for its {$requiredRoomType} requirement.");
        }

        if ($room === null) {
            if ($deliveryMode === 'on-site' && SchedulingPolicy::allowsRoomTbaFallback($course, $departmentId, $meetingType, $fieldCourseCodes)) {
                return null;
            }

            return $violation('A physical room is required for this schedule.');
        }

        if ($deliveryMode === 'field') {
            return $roomType === 'field' ? null : $violation('Field schedules must use a field room assignment.');
        }

        // On-site: reject virtual (online/field) rooms for physical delivery.
        if (in_array($roomType, ['online', 'field'], true)) {
            return $violation("Course {$courseCode} requires a physical room, but '{$roomCode}' is a '{$roomType}' room.");
        }

        if ($requiredRoomType === 'laboratory' && $roomType !== 'laboratory') {
            return $violation("Course {$courseCode} requires a laboratory room, but '{$roomCode}' is a '{$roomType}' room.");
        }

        if ($requiredRoomType === 'lecture' && $roomType === 'laboratory'
            && ! SchedulingPolicy::laboratoryServesLecture($course, $room)) {
            return $violation("Course {$courseCode} can only use lecture-capable laboratory rooms as a fallback.");
        }

        if ($requiredRoomType === 'lecture' && in_array($roomType, ['lecture', 'laboratory'], true)) {
            return null;
        }

        if ($requiredRoomType === 'laboratory' && $roomType === 'laboratory') {
            return null;
        }

        return $requiredRoomType === $roomType
            ? null
            : $violation("Course {$courseCode} requires a '{$requiredRoomType}' room, but '{$roomCode}' is a '{$roomType}' room.");
    }
}
