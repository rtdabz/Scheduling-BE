<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Models\Course;
use App\Models\Rooms;
use App\Models\Sections;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseSetupOverrides
{
    public const PREFERRED_ROOMS_KEY = 'preferred_rooms_by_course_id';

    /**
     * @param  list<string>|null  $allowedDays
     */
    public static function assertSundayAllowed(Sections $section, ?array $allowedDays): void
    {
        if ($allowedDays === null || ! in_array('Sunday', $allowedDays, true)) {
            return;
        }

        $enabled = (bool) DB::table('departments')
            ->where('id', (int) $section->department_id)
            ->value('sunday_classes_enabled');
        if (! $enabled) {
            throw ValidationException::withMessages(['allowed_days' => 'Sunday classes are not enabled for this department. Remove Sunday from the Preferred Days, or ask the department secretary to enable Sunday classes.']);
        }
    }

    public static function assertRequiredDaysAllowed(Sections $section, array $courseIds, ?array $allowedDays): void
    {
        if ($allowedDays === null) {
            return;
        }

        $requiredDays = SchedulingPolicy::forcedCourseDayMap((int) $section->department_id);
        foreach (array_map('intval', $courseIds) as $courseId) {
            $day = $requiredDays[$courseId] ?? null;
            if ($day === null || in_array($day, $allowedDays, true)) {
                continue;
            }

            $code = (string) (Course::query()->whereKey($courseId)->value('course_code') ?? "Course {$courseId}");
            throw ValidationException::withMessages(['allowed_days' => sprintf(
                '%s has a Required Day of %s, which is not one of the Preferred Days (%s). Add %s to the Preferred Days, or change its Required Day in Setup Courses.',
                $code,
                $day,
                implode(', ', $allowedDays),
                $day,
            )]);
        }
    }

    /** @param array<string, mixed> $options */
    public static function preferredRoomId(array $options, int $courseId): ?int
    {
        $roomId = (int) ($options[self::PREFERRED_ROOMS_KEY][$courseId] ?? 0);

        return $roomId > 0 ? $roomId : null;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  list<int>  $courseIds
     * @param  array<string, mixed>  $sectionConfig
     * @return array<int, int>
     * @throws ValidationException
     */
    public static function normalizePreferredRooms(Sections $section, array $raw, array $courseIds, array $sectionConfig = []): array
    {
        $requested = [];
        $valid = array_flip(array_map('intval', $courseIds));
        foreach ($raw as $courseId => $roomId) {
            if ((int) $courseId > 0 && (int) $roomId > 0 && isset($valid[(int) $courseId])) {
                $requested[(int) $courseId] = (int) $roomId;
            }
        }
        if ($requested === []) {
            return [];
        }

        $departmentId = (int) $section->department_id;
        $courses = Course::query()->whereIn('id', array_keys($requested))->get()->keyBy('id');
        $rooms = Rooms::query()->whereIn('id', array_values($requested))->get()->keyBy('id');
        $reachable = array_flip(Rooms::query()
            ->whereIn('id', array_values($requested))
            ->tap(fn ($query) => app(RoomAccessPolicy::class)->scopeReachableRooms($query, $departmentId, (int) $section->semester_id ?: null))
            ->pluck('id')
            ->map('intval')
            ->all());
        $modes = $sectionConfig['delivery_modes_by_course_id'] ?? [];
        $hybridLaboratoryIds = array_map('intval', $sectionConfig['selected_split_session_course_ids'] ?? []);
        $normalized = [];

        foreach ($requested as $courseId => $roomId) {
            $course = $courses->get($courseId);
            if ($course === null) {
                continue;
            }
            $mode = (string) ($modes[$courseId] ?? $modes[(string) $courseId] ?? 'on-site');
            if ($mode === 'online') {
                continue;
            }
            $isField = $mode === 'field' || SchedulingPolicy::isFieldCourse($course, $departmentId);

            $code = (string) ($course->course_code ?? "Course {$courseId}");
            $room = $rooms->get($roomId);
            if ($room === null || (string) $room->status !== 'available') {
                self::failRoom("{$code}: the preferred room is not available. Choose another room or clear the preference.");
            }
            if (! isset($reachable[$roomId])) {
                self::failRoom("{$code}: {$room->room_code} is not a room this department can use this semester.");
            }

            $needsLaboratory = ! $isField
                && (in_array($courseId, $hybridLaboratoryIds, true) || SchedulingPolicy::isLaboratoryCourse($course));
            $fits = match (true) {
                $isField => $room->room_type === 'field',
                $needsLaboratory => in_array($room->room_type, SchedulingPolicy::labRoomTypes($departmentId), true),
                default => $room->room_type === 'lecture' || SchedulingPolicy::laboratoryServesLecture($course, $room),
            };
            if (! $fits) {
                self::failRoom(sprintf(
                    '%s: %s is a %s room, but this course meets in a %s room.',
                    $code,
                    $room->room_code,
                    $room->room_type,
                    $isField ? 'field' : ($needsLaboratory ? implode(' or ', SchedulingPolicy::labRoomTypes($departmentId)) : 'lecture'),
                ));
            }

            $normalized[$courseId] = $roomId;
        }

        return $normalized;
    }

    private static function failRoom(string $message): never
    {
        throw ValidationException::withMessages(['preferred_rooms_by_course_id' => $message]);
    }
}
