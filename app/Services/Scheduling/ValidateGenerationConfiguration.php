<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Services\Scheduling\Constraints\SchedulingConstraintPredicates;
use App\Services\Scheduling\Domain\ConstraintViolation;
use App\Services\Scheduling\Domain\GenerationConfiguration;
use App\Services\Scheduling\Domain\GenerationConfigurationRecommendation;
use App\Services\Scheduling\Domain\GenerationConfigurationValidationResult;
use App\Services\Scheduling\Domain\SchedulingSnapshot;

final class ValidateGenerationConfiguration
{
    public function __construct(
        private readonly SchedulingSnapshotRepository $snapshots,
    ) {}

    public function validate(
        int $termId,
        int $departmentId,
        GenerationConfiguration $configuration,
    ): GenerationConfigurationValidationResult {
        return $this->validateSnapshot(
            $configuration,
            $this->snapshots->captureForConfiguration($termId, $departmentId, $configuration),
        );
    }

    public function validateSnapshot(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
    ): GenerationConfigurationValidationResult {
        $violations = [];
        $recommendations = [];
        $section = $snapshot->sectionsById[$configuration->sectionId] ?? null;

        if (! is_array($section)) {
            $violations[] = $this->violation(
                'section_exists',
                'The configured section is not available in this department and term snapshot.',
                ['section_id' => $configuration->sectionId],
            );

            return $this->result($configuration, $snapshot, $violations, $recommendations);
        }

        $this->validateSection($section, $snapshot, $violations);
        $this->validateReferences($configuration, $snapshot, $violations, $recommendations);
        $this->validateCourses($configuration, $section, $snapshot, $violations, $recommendations);
        $this->validateDepartmentProfile($configuration, $snapshot, $violations, $recommendations);
        $this->validateRooms($configuration, $snapshot, $violations, $recommendations);
        $this->validateForcedDays($configuration, $snapshot, $violations, $recommendations);

        return $this->result($configuration, $snapshot, $violations, $recommendations);
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<ConstraintViolation>  $violations
     */
    private function validateSection(
        array $section,
        SchedulingSnapshot $snapshot,
        array &$violations,
    ): void {
        if ((int) ($section['term_id'] ?? 0) !== $snapshot->termId) {
            $violations[] = $this->violation(
                'section_term_alignment',
                'The configured section does not belong to the selected academic term.',
                ['section_id' => (int) ($section['id'] ?? 0), 'term_id' => $snapshot->termId],
            );
        }

        if ((int) ($section['department_id'] ?? 0) !== $snapshot->departmentId) {
            $violations[] = $this->violation(
                'schedule_department_alignment',
                'The configured section does not belong to the selected department.',
                ['section_id' => (int) ($section['id'] ?? 0), 'department_id' => $snapshot->departmentId],
            );
        }

        if ((string) ($section['status'] ?? '') !== 'active') {
            $violations[] = $this->violation(
                'section_active',
                'The selected section is not active.',
                ['section_id' => (int) ($section['id'] ?? 0), 'status' => (string) ($section['status'] ?? '')],
            );
        }

        if ((string) ($section['semester'] ?? '') !== (string) ($snapshot->term['semester'] ?? '')) {
            $violations[] = $this->violation(
                'section_term_semester_alignment',
                'The section semester does not match the selected academic term.',
                ['section_semester' => $section['semester'] ?? null, 'term_semester' => $snapshot->term['semester'] ?? null],
            );
        }
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function validateReferences(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        array &$violations,
        array &$recommendations,
    ): void {
        $selected = array_fill_keys($configuration->courseIds, true);
        $references = [
            'preferred_patterns' => array_keys($configuration->preferredPatternsByCourseId),
            'selected_split_session_course_ids' => $configuration->selectedSplitSessionCourseIds,
            'balanced_split_course_ids' => $configuration->balancedSplitCourseIds,
            'delivery_modes_by_course_id' => array_keys($configuration->deliveryModesByCourseId),
            'requirements_by_course_id' => array_keys($configuration->requirementsByCourseId),
            'anchored_schedules' => array_keys($configuration->anchoredSchedulesByCourseId),
        ];

        foreach ($references as $field => $courseIds) {
            foreach (array_unique(array_map('intval', $courseIds)) as $courseId) {
                if (isset($selected[$courseId])) {
                    continue;
                }

                $course = $snapshot->coursesById[$courseId] ?? [];
                $violations[] = $this->violation(
                    'configuration_reference',
                    'A course-specific option references a course that is not selected for generation.',
                    ['field' => $field, 'course_id' => $courseId],
                );
                $recommendations[] = $this->recommendation(
                    id: "remove-reference-{$field}-{$courseId}",
                    title: 'Remove the unselected course option',
                    cause: "The {$field} option references an unselected course.",
                    adjustment: 'Remove this option or add the course to the generation request.',
                    impact: 'low',
                    configuration: $configuration,
                    courseId: $courseId,
                    course: $course,
                    adjustments: [[
                        'type' => 'remove_configuration_reference',
                        'field' => $field,
                        'section_id' => $configuration->sectionId,
                        'course_id' => $courseId,
                        'value' => null,
                    ]],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function validateCourses(
        GenerationConfiguration $configuration,
        array $section,
        SchedulingSnapshot $snapshot,
        array &$violations,
        array &$recommendations,
    ): void {
        $sectionSemester = $this->semesterNumber((string) ($section['semester'] ?? ''));

        foreach ($configuration->courseIds as $courseId) {
            $course = $snapshot->coursesById[$courseId] ?? null;
            if (! is_array($course)) {
                $violations[] = $this->violation('subject_exists', 'A selected course could not be loaded.', ['course_id' => $courseId]);
                $recommendations[] = $this->removeCourseRecommendation($configuration, $courseId, [], 'The selected course no longer exists or is outside the snapshot scope.');

                continue;
            }

            if ((string) ($course['status'] ?? '') !== 'active') {
                $violations[] = $this->violation('subject_active', 'A selected course is not active.', $this->courseContext($course));
                $recommendations[] = $this->removeCourseRecommendation($configuration, $courseId, $course, 'The selected course is inactive.');
            }

            // Resolve the placement through the section's own curriculum. Reading
            // the course-keyed map directly would validate an old-curriculum
            // cohort against the new curriculum's placement for the same course,
            // rejecting courses the generator had every right to select.
            $period = $snapshot->periodFor((int) ($section['id'] ?? 0), $courseId);
            if (! is_array($period)
                || (int) ($period['year_level'] ?? 0) !== (int) ($section['year_level'] ?? 0)
                || (int) ($period['semester'] ?? 0) !== $sectionSemester) {
                $violations[] = $this->violation(
                    'subject_section_alignment',
                    'A selected course does not match the section year level and semester in the curriculum it follows.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->removeCourseRecommendation($configuration, $courseId, $course, 'The course is outside this section curriculum period.');
            }

            if (SchedulingConstraintPredicates::isMajorCourse($course)
                && ($course['department_id'] ?? null) !== null
                && (int) $course['department_id'] !== $snapshot->departmentId) {
                $violations[] = $this->violation(
                    'major_department_alignment',
                    'A selected major course belongs to another department.',
                    $this->courseContext($course),
                );
            }

            if (! $this->hasValidDuration($course, $snapshot)) {
                $violations[] = $this->violation(
                    'course_duration',
                    'A selected course has a duration that cannot fit the scheduling grid.',
                    $this->courseContext($course),
                );
            }

            $mode = $configuration->deliveryModesByCourseId[$courseId] ?? $configuration->deliveryMode;
            $isLectureLabSplit = in_array($courseId, $configuration->selectedSplitSessionCourseIds, true);
            $isMinorSplit = in_array($courseId, $configuration->balancedSplitCourseIds, true);

            if ($isLectureLabSplit && (! (bool) ($snapshot->departmentSettings['lecture_lab_schedule_override_enabled'] ?? false)
                || ! SchedulingConstraintPredicates::isMajorCourse($course)
                || (int) ($course['lecture_hours'] ?? 0) <= 0
                || (int) ($course['lab_hours'] ?? 0) <= 0)) {
                $violations[] = $this->violation(
                    'hybrid_eligibility',
                    'Lecture/laboratory splitting requires an eligible major course and the department override.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->disableSplitRecommendation($configuration, $course, 'disable_lecture_lab_split');
            }

            if ($isMinorSplit && (! (bool) ($snapshot->departmentSettings['gec_split_schedule_override_enabled'] ?? false)
                || SchedulingConstraintPredicates::isMajorCourse($course))) {
                $violations[] = $this->violation(
                    'minor_split_eligibility',
                    'Minor split sessions require an eligible minor course and the department setting.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->disableSplitRecommendation($configuration, $course, 'disable_minor_split');
            }

            if ($configuration->isHybrid && $mode === 'field') {
                $violations[] = $this->violation('hybrid_mode', 'Field delivery cannot be combined with Hybrid generation.', $this->courseContext($course));
            }

            if ($mode === 'online'
                && ! $isLectureLabSplit
                && ! SchedulingConstraintPredicates::allowsOnline($course, $snapshot->fieldCourseCodes)) {
                $violations[] = $this->violation(
                    'room_type_match',
                    'This course cannot be generated as an online-only meeting.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->deliveryModeRecommendation($configuration, $course, 'automatic');
            }

            if ($mode === 'field' && SchedulingConstraintPredicates::isLaboratoryCourse($course)) {
                $violations[] = $this->violation(
                    'room_type_match',
                    'A laboratory course cannot be converted to field delivery.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->deliveryModeRecommendation($configuration, $course, 'automatic');
            }
        }
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function validateDepartmentProfile(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        array &$violations,
        array &$recommendations,
    ): void {
        if (($snapshot->departmentSettings['scheduling_profile'] ?? 'standard') !== 'standard') {
            return;
        }

        foreach ($configuration->courseIds as $courseId) {
            $course = $snapshot->coursesById[$courseId] ?? null;
            if (is_array($course) && SchedulingConstraintPredicates::isLaboratoryCourse($course)) {
                $violations[] = $this->violation(
                    'department_profile_mismatch',
                    'A standard department profile cannot generate laboratory course components.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->removeCourseRecommendation($configuration, $courseId, $course, 'The department profile does not support laboratory components.');
            }
        }

        foreach ([
            'lecture_lab_schedule_override_enabled',
            'custom_lab_duration_override_enabled',
            'custom_lab_duration_6_hours_enabled',
            'custom_lab_duration_5_hours_enabled',
            'custom_lab_duration_other_enabled',
        ] as $setting) {
            if (! (bool) ($snapshot->departmentSettings[$setting] ?? false)) {
                continue;
            }

            $violations[] = $this->violation(
                'invalid_department_setting',
                "The standard department profile has {$setting} enabled.",
                ['setting' => $setting],
            );
        }
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function validateRooms(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        array &$violations,
        array &$recommendations,
    ): void {
        $availableRooms = array_values(array_filter(
            $snapshot->roomsById,
            fn (array $room): bool => (string) ($room['status'] ?? '') === 'available'
                && (($room['department_id'] ?? null) === null || (int) $room['department_id'] === $snapshot->departmentId),
        ));
        $hasLectureRoom = collect($availableRooms)->contains(static fn (array $room): bool => ($room['room_type'] ?? null) === 'lecture');
        $hasLaboratoryRoom = collect($availableRooms)->contains(static fn (array $room): bool => ($room['room_type'] ?? null) === 'laboratory');

        foreach ($configuration->courseIds as $courseId) {
            $course = $snapshot->coursesById[$courseId] ?? null;
            if (! is_array($course)) {
                continue;
            }

            $mode = $configuration->deliveryModesByCourseId[$courseId] ?? $configuration->deliveryMode;
            $isLectureLabSplit = in_array($courseId, $configuration->selectedSplitSessionCourseIds, true);
            $isLaboratory = SchedulingConstraintPredicates::isLaboratoryCourse($course);

            $explicitlyOnSite = ($configuration->deliveryModesByCourseId[$courseId] ?? null) === 'on-site';
            $standardProfile = ($snapshot->departmentSettings['scheduling_profile'] ?? 'standard') === 'standard';
            if ($mode === 'on-site' && ! $isLaboratory && ! $isLectureLabSplit && ! $hasLectureRoom
                && ($standardProfile || $explicitlyOnSite)) {
                $violations[] = $this->violation(
                    'no_physical_rooms',
                    'No eligible lecture room is available for an on-site course.',
                    $this->courseContext($course),
                );
                $recommendations[] = $this->deliveryModeRecommendation($configuration, $course, 'online');
            }

            if (($isLaboratory || $isLectureLabSplit) && ! $hasLaboratoryRoom) {
                $severity = $configuration->allowRoomTbaFallback ? 'warning' : 'hard';
                $ruleId = $configuration->allowRoomTbaFallback ? 'laboratory_room_unresolved' : 'no_physical_rooms';
                $violations[] = $this->violation(
                    $ruleId,
                    $configuration->allowRoomTbaFallback
                        ? 'No eligible laboratory room is available; generation may return Room TBA.'
                        : 'No eligible laboratory room is available and Room TBA fallback is disabled.',
                    $this->courseContext($course),
                    $severity,
                );
                $recommendations[] = $this->recommendation(
                    id: "laboratory-room-{$courseId}",
                    title: 'Provide laboratory capacity',
                    cause: 'The selected course requires a laboratory but no eligible laboratory room is available.',
                    adjustment: 'Add or re-enable a laboratory room, or allow Room TBA and resolve the room before finalization.',
                    impact: 'high',
                    configuration: $configuration,
                    courseId: $courseId,
                    course: $course,
                );
            }
        }
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function validateForcedDays(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        array &$violations,
        array &$recommendations,
    ): void {
        $byDay = [];
        foreach ($configuration->courseIds as $courseId) {
            $day = $snapshot->forcedDaysByCourseId[$courseId] ?? null;
            if (is_string($day) && $day !== '') {
                $byDay[$day][] = $courseId;
            }
        }

        foreach ($byDay as $day => $courseIds) {
            if (count($courseIds) >= 2) {
                $violations[] = $this->violation(
                    'same_day_concentration',
                    sprintf('All %d forced-day courses are assigned to %s. The schedule may be too concentrated and should be reviewed.', count($courseIds), $day),
                    ['day' => $day, 'course_ids' => $courseIds, 'course_count' => count($courseIds)],
                    'warning',
                );
                $recommendations[] = $this->forcedDayRecommendation($configuration, $snapshot, $day, array_slice($courseIds, 1), 'Review the same-day concentration and let the generator choose days for some courses.');
            }

            $singleMeetingIds = [];
            foreach ($courseIds as $courseId) {
                $hasMultipleMeetings = array_key_exists($courseId, $configuration->preferredPatternsByCourseId)
                    || in_array($courseId, $configuration->selectedSplitSessionCourseIds, true)
                    || in_array($courseId, $configuration->balancedSplitCourseIds, true);
                if ($hasMultipleMeetings) {
                    $course = $snapshot->coursesById[$courseId] ?? [];
                    $violations[] = $this->violation(
                        'forced_day_multi_meeting_conflict',
                        'A course forced to one day cannot satisfy a configuration that requires meetings on different days.',
                        ['day' => $day, ...$this->courseContext($course, $courseId)],
                    );
                    $recommendations[] = $this->forcedDayRecommendation($configuration, $snapshot, $day, [$courseId], 'Clear the forced day or remove the multi-day course configuration.');
                } else {
                    $singleMeetingIds[] = $courseId;
                }
            }

            $requiredSlots = array_sum(array_map(
                fn (int $courseId): int => $this->courseSlots($snapshot->coursesById[$courseId] ?? []),
                $singleMeetingIds,
            ));
            $availableSlots = $this->dailySlots($snapshot);
            if ($requiredSlots > $availableSlots) {
                $violations[] = $this->violation(
                    'forced_day_capacity_exceeded',
                    "Courses forced to {$day} require {$requiredSlots} section slots but only {$availableSlots} are available.",
                    [
                        'day' => $day,
                        'course_ids' => $singleMeetingIds,
                        'required_slots' => $requiredSlots,
                        'available_slots' => $availableSlots,
                    ],
                );
                $recommendations[] = $this->forcedDayRecommendation($configuration, $snapshot, $day, $singleMeetingIds, 'Release enough forced-day rules to fit the section within operating hours.');
            }
        }
    }

    /**
     * @param  list<ConstraintViolation>  $violations
     * @param  list<GenerationConfigurationRecommendation>  $recommendations
     */
    private function result(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        array $violations,
        array $recommendations,
    ): GenerationConfigurationValidationResult {
        $recommendationsById = [];
        foreach ($recommendations as $recommendation) {
            $recommendationsById[$recommendation->id] = $recommendation;
        }

        return new GenerationConfigurationValidationResult(
            configuration: $configuration,
            snapshotFingerprint: $snapshot->fingerprint,
            violations: $violations,
            recommendations: array_values($recommendationsById),
            metadata: [
                'term_id' => $snapshot->termId,
                'department_id' => $snapshot->departmentId,
                'section_id' => $configuration->sectionId,
                'validated_course_count' => count($configuration->courseIds),
                'snapshot_query_count' => (int) ($snapshot->metadata['snapshot_query_count'] ?? 0),
                'snapshot_elapsed_ms' => (float) ($snapshot->metadata['snapshot_elapsed_ms'] ?? 0.0),
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function violation(
        string $ruleId,
        string $message,
        array $context = [],
        ?string $severity = null,
    ): ConstraintViolation {
        return new ConstraintViolation(
            ruleId: $ruleId,
            message: $message,
            severity: $severity ?? (string) (SchedulingPolicy::CONSTRAINT_CATALOG[$ruleId]['severity'] ?? 'hard'),
            scope: 'generation_configuration',
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $course
     * @param  list<array<string, mixed>>  $adjustments
     */
    private function recommendation(
        string $id,
        string $title,
        string $cause,
        string $adjustment,
        string $impact,
        GenerationConfiguration $configuration,
        ?int $courseId = null,
        array $course = [],
        array $adjustments = [],
    ): GenerationConfigurationRecommendation {
        return new GenerationConfigurationRecommendation(
            id: $id,
            title: $title,
            detectedCause: $cause,
            suggestedAdjustment: $adjustment,
            impact: $impact,
            adjustments: $adjustments,
            sectionId: $configuration->sectionId,
            sectionName: null,
            courseId: $courseId,
            courseCode: isset($course['course_code']) ? (string) $course['course_code'] : null,
        );
    }

    /** @param array<string, mixed> $course */
    private function removeCourseRecommendation(
        GenerationConfiguration $configuration,
        int $courseId,
        array $course,
        string $cause,
    ): GenerationConfigurationRecommendation {
        return $this->recommendation(
            id: "remove-course-{$courseId}",
            title: 'Remove the invalid course from this run',
            cause: $cause,
            adjustment: 'Remove the course or correct its curriculum and status before generating.',
            impact: 'high',
            configuration: $configuration,
            courseId: $courseId,
            course: $course,
            adjustments: [[
                'type' => 'remove_course',
                'section_id' => $configuration->sectionId,
                'course_id' => $courseId,
                'value' => null,
            ]],
        );
    }

    /** @param array<string, mixed> $course */
    private function disableSplitRecommendation(
        GenerationConfiguration $configuration,
        array $course,
        string $type,
    ): GenerationConfigurationRecommendation {
        $courseId = (int) ($course['id'] ?? 0);

        return $this->recommendation(
            id: "{$type}-{$courseId}",
            title: 'Disable the incompatible split configuration',
            cause: 'The selected course does not satisfy the configured split requirements.',
            adjustment: 'Disable this split option or correct the department and course configuration.',
            impact: 'medium',
            configuration: $configuration,
            courseId: $courseId,
            course: $course,
            adjustments: [[
                'type' => $type,
                'section_id' => $configuration->sectionId,
                'course_id' => $courseId,
                'value' => null,
            ]],
        );
    }

    /** @param array<string, mixed> $course */
    private function deliveryModeRecommendation(
        GenerationConfiguration $configuration,
        array $course,
        string $mode,
    ): GenerationConfigurationRecommendation {
        $courseId = (int) ($course['id'] ?? 0);

        return $this->recommendation(
            id: "delivery-mode-{$courseId}-{$mode}",
            title: 'Adjust the course delivery mode',
            cause: 'The selected delivery mode cannot satisfy the course resource requirement.',
            adjustment: $mode === 'automatic'
                ? 'Return the course to Automatic so the generator can use any eligible delivery mode.'
                : 'Set the course to Online to remove its physical lecture-room requirement.',
            impact: 'medium',
            configuration: $configuration,
            courseId: $courseId,
            course: $course,
            adjustments: [[
                'type' => 'set_delivery_mode',
                'section_id' => $configuration->sectionId,
                'course_id' => $courseId,
                'value' => $mode,
            ]],
        );
    }

    /** @param list<int> $courseIds */
    private function forcedDayRecommendation(
        GenerationConfiguration $configuration,
        SchedulingSnapshot $snapshot,
        string $day,
        array $courseIds,
        string $suggestedAdjustment,
    ): GenerationConfigurationRecommendation {
        $courseIds = array_values(array_unique(array_map('intval', $courseIds)));
        $firstCourseId = $courseIds[0] ?? null;
        $course = $firstCourseId === null ? [] : ($snapshot->coursesById[$firstCourseId] ?? []);

        return $this->recommendation(
            id: 'clear-forced-day-'.strtolower($day).'-'.implode('-', $courseIds),
            title: "Reduce the {$day} forced-day concentration",
            cause: "The selected forced-day rules concentrate too much demand on {$day}.",
            adjustment: $suggestedAdjustment,
            impact: 'medium',
            configuration: $configuration,
            courseId: $firstCourseId,
            course: $course,
            adjustments: array_map(static fn (int $courseId): array => [
                'type' => 'clear_forced_day',
                'section_id' => $configuration->sectionId,
                'course_id' => $courseId,
                'value' => null,
            ], $courseIds),
        );
    }

    /** @param array<string, mixed> $course */
    private function hasValidDuration(array $course, SchedulingSnapshot $snapshot): bool
    {
        $rawSlots = (float) ($course['units'] ?? 0) * 2;

        return $rawSlots > 0
            && abs($rawSlots - round($rawSlots)) <= 0.00001
            && $rawSlots <= $this->dailySlots($snapshot);
    }

    /** @param array<string, mixed> $course */
    private function courseSlots(array $course): int
    {
        return max(0, (int) round((float) ($course['units'] ?? 0) * 2));
    }

    private function dailySlots(SchedulingSnapshot $snapshot): int
    {
        $opening = (string) ($snapshot->operatingHours['opening_time'] ?? '07:00');
        $closing = (string) ($snapshot->operatingHours['closing_time'] ?? '21:00');
        $slotMinutes = max(1, (int) ($snapshot->operatingHours['slot_minutes'] ?? SchedulingPolicy::SLOT_MINUTES));

        return max(0, intdiv(
            SchedulingPolicy::timeToMinutes($closing) - SchedulingPolicy::timeToMinutes($opening),
            $slotMinutes,
        ));
    }

    private function semesterNumber(string $semester): int
    {
        return match ($semester) {
            '1st' => 1,
            '2nd' => 2,
            'summer' => 3,
            default => 0,
        };
    }

    /** @param array<string, mixed> $course */
    private function courseContext(array $course, ?int $fallbackId = null): array
    {
        return [
            'course_id' => (int) ($course['id'] ?? $fallbackId ?? 0),
            'course_code' => isset($course['course_code']) ? (string) $course['course_code'] : null,
        ];
    }
}
