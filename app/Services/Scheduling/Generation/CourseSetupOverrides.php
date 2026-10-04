<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Models\Course;
use App\Models\Rooms;
use App\Models\Sections;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseSetupOverrides
{
    public const DURATIONS_KEY = 'duration_slots_by_course_id';

    public const COMPONENTS_KEY = 'component_slots_by_course_id';

    public const PREFERRED_ROOMS_KEY = 'preferred_rooms_by_course_id';

    /** @param array<string, mixed> $options */
    public static function durationSlots(array $options, int $courseId): ?int
    {
        $slots = $options[self::DURATIONS_KEY][$courseId] ?? null;

        return is_int($slots) && $slots > 0 ? $slots : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function componentSlots(array $options, int $courseId, string $component): ?int
    {
        $slots = $options[self::COMPONENTS_KEY][$courseId][$component] ?? null;

        return is_int($slots) && $slots > 0 ? $slots : null;
    }

    /**
     * @param  array<int|string, mixed>  $minutesByCourseId  courseId => {lecture?, laboratory?}
     * @param  list<int>  $courseIds
     * @param  array<string, mixed>  $sectionConfig
     * @return array<int, array{lecture: int, laboratory: int}>
     * @throws ValidationException
     */
    public static function normalizeComponents(Sections $section, array $minutesByCourseId, array $courseIds, array $sectionConfig): array
    {
        $integratedIds = array_values(array_intersect(
            array_map('intval', $sectionConfig['selected_split_session_course_ids'] ?? []),
            array_map('intval', $courseIds),
        ));
        $wanted = array_values(array_filter(
            $integratedIds,
            static fn (int $id): bool => is_array($minutesByCourseId[$id] ?? $minutesByCourseId[(string) $id] ?? null),
        ));
        if ($wanted === []) {
            return [];
        }

        $courses = Course::query()->whereIn('id', $wanted)->get()->keyBy('id');
        $normalized = [];
        foreach ($wanted as $courseId) {
            $course = $courses->get($courseId);
            if ($course === null) {
                continue;
            }

            $code = (string) ($course->course_code ?? "Course {$courseId}");
            $minutes = $minutesByCourseId[$courseId] ?? $minutesByCourseId[(string) $courseId];
            $lecture = self::componentMinutesToSlots($code, 'lecture', $minutes['lecture'] ?? null)
                ?? SchedulingPolicy::lectureComponentSlots($course);
            $laboratory = self::componentMinutesToSlots($code, 'laboratory', $minutes['laboratory'] ?? null)
                ?? SchedulingPolicy::laboratoryComponentSlots($course, $section->department);

            self::assertFitsTheDay($code, 'lecture', $lecture);
            self::assertFitsTheDay($code, 'laboratory', $laboratory);

            $normalized[$courseId] = ['lecture' => $lecture, 'laboratory' => $laboratory];
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $courseIds
     * @param  list<string>|null  $allowedDays
     * @throws ValidationException
     */
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

    private static function componentMinutesToSlots(string $code, string $label, mixed $minutes): ?int
    {
        if ($minutes === null || $minutes === '') {
            return null;
        }

        $minutes = (int) $minutes;
        if ($minutes <= 0 || $minutes % SchedulingPolicy::SLOT_MINUTES !== 0) {
            self::fail("{$code}: the {$label} duration must be a whole number of half-hours.");
        }

        return intdiv($minutes, SchedulingPolicy::SLOT_MINUTES);
    }

    /** @param array<string, mixed> $options */
    public static function preferredRoomId(array $options, int $courseId): ?int
    {
        $roomId = (int) ($options[self::PREFERRED_ROOMS_KEY][$courseId] ?? 0);

        return $roomId > 0 ? $roomId : null;
    }

    /**
     * @param  array<int|string, mixed>  $minutesByCourseId
     * @param  list<int>  $courseIds
     * @param  array<string, mixed>  $sectionConfig
     * @return array<int, int>
     * @throws ValidationException
     */
    public static function normalizeDurations(Sections $section, array $minutesByCourseId, array $courseIds, array $sectionConfig): array
    {
        $requested = [];
        foreach ($minutesByCourseId as $courseId => $minutes) {
            if ($minutes !== null && $minutes !== '') {
                $requested[(int) $courseId] = (int) $minutes;
            }
        }
        $wanted = array_values(array_intersect(array_keys($requested), array_map('intval', $courseIds)));
        if ($wanted === []) {
            return [];
        }

        $fixedShapeIds = array_map('intval', [
            ...($sectionConfig['selected_split_session_course_ids'] ?? []),
            ...($sectionConfig['hybrid_split_course_ids'] ?? []),
        ]);
        $balancedSplitIds = array_map('intval', $sectionConfig['balanced_split_course_ids'] ?? []);

        /** @var Collection<int, Course> $courses */
        $courses = Course::query()->whereIn('id', $wanted)->get()->keyBy('id');
        $normalized = [];

        foreach ($wanted as $courseId) {
            $course = $courses->get($courseId);
            if ($course === null || in_array($courseId, $fixedShapeIds, true)) {
                continue;
            }

            $code = (string) ($course->course_code ?? "Course {$courseId}");
            $minutes = $requested[$courseId];
            if ($minutes <= 0 || $minutes % SchedulingPolicy::SLOT_MINUTES !== 0) {
                self::fail("{$code}: the duration must be a whole number of half-hours.");
            }
            $slots = intdiv($minutes, SchedulingPolicy::SLOT_MINUTES);

            if (in_array($courseId, $balancedSplitIds, true)) {
                self::assertWithinCeiling($code, $minutes, SchedulingPolicy::unitMinutes($course->units ?? 0));
                if ($slots % 2 !== 0) {
                    self::fail("{$code}: the two Split Session meetings must be the same length, so each must be a whole number of half-hours.");
                }
                self::assertFitsTheDay($code, 'meeting', intdiv($slots, 2));
            } else {
                self::assertFitsTheDay($code, 'class', $slots);
            }

            $normalized[$courseId] = $slots;
        }

        return $normalized;
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

    private static function assertWithinCeiling(string $code, int $minutes, int $ceiling): void
    {
        if ($ceiling > 0 && $minutes > $ceiling) {
            self::fail(sprintf(
                '%s: a custom duration of %s is longer than the %s a week the course carries.',
                $code,
                self::hours($minutes),
                self::hours($ceiling),
            ));
        }
    }

    private static function assertFitsTheDay(string $code, string $label, int $slots): void
    {
        if (SchedulingPolicy::generatedStartSlotsForDuration($slots) === []) {
            self::fail(sprintf(
                '%s: a %s %s has no start time inside the teaching day.',
                $code,
                self::hours($slots * SchedulingPolicy::SLOT_MINUTES),
                $label,
            ));
        }
    }

    private static function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').($hours === 1.0 ? ' hour' : ' hours');
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['duration_minutes_by_course_id' => $message]);
    }
}
