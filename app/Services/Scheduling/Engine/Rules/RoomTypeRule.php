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
        $requiredRoomType = SchedulingPolicy::effectiveRoomType($course, $departmentId, $meetingType);

        if ($deliveryMode === 'online') {
            return SchedulingPolicy::allowsOnlineRoomFallback($course, $departmentId, $meetingType)
                ? null
                : [
                    'rule' => 'room_type_match',
                    'message' => "Course {$course->course_code} cannot use online delivery for its {$requiredRoomType} requirement.",
                ];
        }

        if (! $room) {
            if ($deliveryMode === 'on-site' && SchedulingPolicy::allowsRoomTbaFallback($course, $departmentId, $meetingType)) {
                return null;
            }

            return [
                'rule' => 'room_type_match',
                'message' => 'A physical room is required for this schedule.',
            ];
        }

        if ($deliveryMode === 'field') {
            return $room->room_type === 'field'
                ? null
                : [
                    'rule' => 'room_type_match',
                    'message' => 'Field schedules must use a field room assignment.',
                ];
        }

        // On-site: reject virtual (online/field) rooms for physical delivery.
        if (in_array($room->room_type, ['online', 'field'], true)) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a physical room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        if ($requiredRoomType === 'laboratory' && $room->room_type !== 'laboratory') {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a laboratory room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        if (
            $requiredRoomType === 'lecture'
            && $room->room_type === 'laboratory'
            && ! $this->canUseLaboratoryForLecture($course, $room)
        ) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} can only use lecture-capable laboratory rooms as a fallback.",
            ];
        }

        if (
            $requiredRoomType === 'lecture'
            && in_array($room->room_type, ['lecture', 'laboratory'], true)
        ) {
            return null;
        }

        if ($requiredRoomType === 'laboratory' && $room->room_type === 'laboratory') {
            return null;
        }

        if ($requiredRoomType !== $room->room_type) {
            return [
                'rule' => 'room_type_match',
                'message' => "Course {$course->course_code} requires a '{$requiredRoomType}' room, "
                    ."but '{$room->room_code}' is a '{$room->room_type}' room.",
            ];
        }

        return null;
    }

    private function canUseLaboratoryForLecture(Course $course, Rooms $room): bool
    {
        $courseCategory = $course->course_category ?? $course->subject_category ?? 'major';

        return $courseCategory === 'major'
            && (int) ($course->lecture_hours ?? 0) > 0
            && (int) ($course->lab_hours ?? 0) === 0
            && (string) ($course->room_type_required ?? 'lecture') === 'lecture'
            && $room->room_type === 'laboratory'
            && (bool) $room->allow_lecture_usage;
    }
}
