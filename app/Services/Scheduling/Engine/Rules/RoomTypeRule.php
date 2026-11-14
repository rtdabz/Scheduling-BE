<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Course;
use App\Models\Rooms;
use App\Services\Scheduling\Support\SchedulingPolicy;

final class RoomTypeRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
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

        if (! SchedulingPolicy::isFieldSchedulingEligible($course)
            && ($deliveryMode === 'field' || $meetingType === 'field' || $roomType === 'field')) {
            return $violation(SchedulingPolicy::LABORATORY_FIELD_MESSAGE);
        }

        if ($meetingType === 'laboratory' && SchedulingPolicy::isMajorWithoutLaboratoryUnits($course)) {
            return $violation("Course {$courseCode} has no laboratory units, so it cannot have a laboratory meeting.");
        }

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

        if (in_array($roomType, ['online', 'field'], true)) {
            return $violation("Course {$courseCode} requires a physical room, but '{$roomCode}' is a '{$roomType}' room.");
        }

        if ($requiredRoomType === 'laboratory') {
            $courseDepartmentId = is_array($course) ? ($course['department_id'] ?? null) : $course->department_id;
            $labDepartmentId = $departmentId ?? ($courseDepartmentId === null ? null : (int) $courseDepartmentId);
            if (in_array($roomType, SchedulingPolicy::labRoomTypes($labDepartmentId), true)) {
                return null;
            }

            return $violation("Course {$courseCode} requires a laboratory room for its laboratory session, but '{$roomCode}' is a '{$roomType}' room. Choose a laboratory room or leave the room as TBA.");
        }

        if ($requiredRoomType === 'lecture' && $roomType === 'laboratory') {
            return $violation(SchedulingPolicy::isMajorWithoutLaboratoryUnits($course)
                ? SchedulingPolicy::NO_LABORATORY_UNITS_MESSAGE
                : "Course {$courseCode} requires a 'lecture' room, but '{$roomCode}' is a 'laboratory' room.");
        }

        return $requiredRoomType === $roomType
            ? null
            : $violation("Course {$courseCode} requires a '{$requiredRoomType}' room, but '{$roomCode}' is a '{$roomType}' room.");
    }
}
