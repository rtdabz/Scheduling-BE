<?php

namespace App\Services\Scheduling\YearLevel;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Services\Scheduling\Generation\CourseSetupOverrides;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Support\Collection;

/**
 * Capacity feasibility pre-check for a whole year-level generation run.
 *
 * ScheduleGenerationPreflightService already validates one section's *data* —
 * course status, curriculum period, whether a room of the required type exists at
 * all. This answers the different question the year-level flow needs before it
 * spends two minutes searching: across every section in the run, is there
 * physically enough room-time for what has been asked for?
 *
 * Only provable shortfalls are reported. Demand must exceed supply arithmetically
 * for a constraint to block; anything that merely looks tight is left to the
 * solver, because refusing a feasible run is worse than searching and failing.
 */
class YearLevelFeasibilityService
{
    /** Statuses whose existing schedules will be replaced by this run. */
    private const REPLACEABLE_STATUSES = ['draft', 'completed', 'revision'];

    /** The run's semester, so rooms granted to the department count as supply. */
    private ?int $semesterId = null;

    /**
     * Step 1's Preferred Days for the run, or null when every day is open.
     * Every weekly supply below counts only these days.
     *
     * @var list<string>|null
     */
    private ?array $allowedDays = null;

    /**
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return list<array<string, mixed>>  blocking constraints, empty when feasible enough to try
     */
    public function check(array $sections, array $configsBySectionId): array
    {
        if ($sections === []) {
            return [];
        }

        $department = $this->resolveDepartment($sections);
        $this->semesterId = (int) $sections[array_key_first($sections)]->semester_id ?: null;
        // One choice for the whole year level; every section carries the same.
        $this->allowedDays = SchedulingPolicy::normalizeAllowedDays(
            $configsBySectionId[(int) $sections[array_key_first($sections)]->id]['allowed_days'] ?? null,
        );
        $courses = $this->courses($configsBySectionId);
        $slotsPerDay = SchedulingPolicy::totalSlots();

        $blocking = [];
        $blocking = [...$blocking, ...$this->checkPhysicalRoomCapacity($sections, $configsBySectionId, $courses, $department, $slotsPerDay)];
        // Laboratory capacity is advisory: the CSP may place a laboratory
        // meeting on-site with Room TBA when no compatible lab slot exists.
        $blocking = [...$blocking, ...$this->checkFixedPatternCapacity($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkForcedDayCapacity($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkComponentDurationsFitTheDay($sections, $configsBySectionId, $courses, $department)];
        $blocking = [...$blocking, ...$this->checkPreferredDays($sections, $configsBySectionId, $courses, $department)];

        return $blocking;
    }

    /**
     * A meeting longer than the teaching day can never be placed.
     *
     * Laboratory units are converted at three hours each, so a six-unit
     * laboratory becomes one eighteen-hour block against a day that is open for
     * thirteen and a half. The generator produces no start time for it, the
     * course's domain is empty before the search begins, and because year-level
     * generation does not throw on an empty domain the whole run fails with the
     * blame landing on some other course in the same section. Catching it here
     * names the real course and says why.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkComponentDurationsFitTheDay(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $splitEnabled = true;
        $blocking = [];
        $reported = [];
        $reportedPeriods = [];

        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $splitIds = array_map('intval', $config['selected_split_session_course_ids'] ?? []);
            $balancedSplitIds = array_map('intval', $config['balanced_split_course_ids'] ?? []);
            $period = SchedulingPolicy::normalizePreferredPeriod($config['preferred_period'] ?? null);
            $periodMeetingSlots = [];
            $periodCourseCount = 0;
            $periodSundayUsable = false;

            foreach ($this->configuredCourses($config, $courses) as $course) {
                $courseId = (int) $course->id;
                // Configure can give one course its own Preferred Meeting; it
                // is then judged against its own windows and left out of the
                // section period's capacity count.
                $coursePeriods = SchedulingPolicy::coursePreferredPeriods($config, $courseId);
                $followsSection = $period !== null && $coursePeriods === [$period];

                if ($followsSection) {
                    $periodCourseCount++;
                    foreach ($this->meetingSlotsForCourse(
                        $course,
                        $splitEnabled && in_array($courseId, $splitIds, true),
                        in_array($courseId, $balancedSplitIds, true),
                        $department,
                        $config,
                    ) as $meetingSlots) {
                        $periodMeetingSlots[] = $meetingSlots;
                    }
                    // Sunday is a seventh teaching day only for the courses
                    // allowed on it: NSTP any mode, a major online (or on-site
                    // once the department opens Sunday). Counting it whenever any
                    // course qualifies keeps the day bound generous, so the check
                    // never refuses a run the solver could have placed.
                    if (SchedulingPolicy::isNstpCourse($course) || SchedulingPolicy::isMajorCourse($course)) {
                        $periodSundayUsable = true;
                    }
                }

                // Checked before the day-width test and keyed per section: a
                // period is a property of the section, so the same course can be
                // impossible for a pinned section and fine for a flexible one.
                if ($coursePeriods !== null && ! isset($reportedPeriods[(int) $section->id][$courseId])) {
                    $blocker = $this->periodWindowBlocker(
                        $section,
                        $course,
                        $coursePeriods,
                        $splitEnabled && in_array($courseId, $splitIds, true),
                        in_array($courseId, $balancedSplitIds, true),
                        $department,
                        $config,
                        coursePreferredMeeting: ! $followsSection,
                    );

                    if ($blocker !== null) {
                        $reportedPeriods[(int) $section->id][$courseId] = true;
                        $blocking[] = $blocker;

                        continue;
                    }
                }

                if (isset($reported[$courseId])) {
                    continue;
                }

                $lectureUnits = (int) ($course->lecture_hours ?? 0);
                $laboratoryUnits = (int) ($course->lab_hours ?? 0);
                $isSplit = $splitEnabled
                    && in_array($courseId, $splitIds, true)
                    && $lectureUnits > 0
                    && $laboratoryUnits > 0;

                $components = $isSplit
                    ? $this->integratedHybridSlots($course, $department, $config)
                    : ['meeting' => $this->configuredSlots($course, $config)];

                foreach ($components as $label => $slots) {
                    if ($slots <= 0 || SchedulingPolicy::generatedStartSlotsForDuration($slots) !== []) {
                        continue;
                    }

                    $reported[$courseId] = true;
                    $blocking[] = [
                        'code' => 'component_duration_exceeds_day',
                        'message' => sprintf(
                            '%s needs a %s block of %s, but the teaching day is only %s long, so it has no possible start time.',
                            (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}"),
                            $label,
                            $this->describeHours($slots),
                            $this->describeHours(SchedulingPolicy::totalSlots()),
                        ),
                        'suggested_action' => $isSplit
                            ? sprintf(
                                'Split %s across more than one weekly session, reduce its laboratory units, '
                                .'or extend the operating hours so a block of %s fits.',
                                (string) ($course->course_code ?? ''),
                                $this->describeHours($slots),
                            )
                            : sprintf(
                                'Reduce the units on %s or extend the operating hours so a block of %s fits.',
                                (string) ($course->course_code ?? ''),
                                $this->describeHours($slots),
                            ),
                        'context' => [
                            'course_id' => $courseId,
                            'course_code' => (string) ($course->course_code ?? ''),
                            'component' => $label,
                            'required_slots' => $slots,
                            'available_slots_per_day' => SchedulingPolicy::totalSlots(),
                            'lecture_units' => $lectureUnits,
                            'laboratory_units' => $laboratoryUnits,
                        ],
                    ];

                    break;
                }
            }

            if ($period !== null && $periodMeetingSlots !== []) {
                $capacity = $this->periodCapacityBlocker(
                    $section,
                    $period,
                    $periodMeetingSlots,
                    SchedulingPolicy::countAllowedDays(
                        $periodSundayUsable ? SchedulingPolicy::DAYS : SchedulingPolicy::WEEKDAYS_AND_SATURDAY,
                        $this->allowedDays,
                    ),
                    $periodCourseCount,
                );

                if ($capacity !== null) {
                    $blocking[] = $capacity;
                }
            }
        }

        return $blocking;
    }

    /**
     * A meeting that cannot fit inside the section's preferred period.
     *
     * A period is a hard window roughly a third of the teaching day, so a block
     * that fits the day can still be unplaceable for a pinned section. The
     * solver discovers this as an empty domain; year-level generation does not
     * throw on an empty domain, so the run used to fail at the search stage and
     * blame the section for competing with others over rooms, when the truth was
     * one course longer than the window. The arithmetic mirrors the solver's own
     * window filter: a legal start time must exist with the whole meeting inside
     * the window.
     *
     * @return array<string, mixed>|null
     */
    private function periodWindowBlocker(
        Sections $section,
        Course $course,
        array $periods,
        bool $isLectureLabSplit,
        bool $isBalancedSplit,
        Departments $department,
        array $config = [],
        bool $coursePreferredMeeting = false,
    ): ?array {
        $windows = SchedulingPolicy::preferredPeriodsSlotRanges($periods);
        $windowSlots = max([0, ...array_map(static fn (array $w): int => $w[1] - $w[0], $windows)]);

        // The longest meeting binds: each is placed separately, so if the biggest
        // one has nowhere to go the course cannot be scheduled at all.
        $required = max($this->meetingSlotsForCourse(
            $course,
            $isLectureLabSplit,
            $isBalancedSplit,
            $department,
            $config,
        ));

        if ($required <= 0) {
            return null;
        }

        foreach (SchedulingPolicy::generatedStartSlotsForDuration($required) as $startSlot) {
            foreach ($windows as [$from, $to]) {
                if ($startSlot >= $from && $startSlot + $required <= $to) {
                    return null;
                }
            }
        }

        $courseCode = (string) ($course->course_code ?? $course->course_name ?? ('Course '.$course->id));
        $periodLabel = SchedulingPolicy::preferredPeriodsLabel($periods);
        $sectionName = (string) $section->section_name;

        // The course's own Preferred Meeting is the cause, so the fix is in
        // Configure. A separate code keeps the section-level "move this
        // section to another session" recommendations out of it.
        if ($coursePreferredMeeting) {
            return [
                'code' => 'component_duration_exceeds_course_period',
                'message' => sprintf(
                    '%s needs a block of %s, which does not fit its Preferred Meeting (%s) in %s: the longest window is only %s.',
                    $courseCode,
                    $this->describeHours($required),
                    $periodLabel,
                    $sectionName,
                    $this->describeHours($windowSlots),
                ),
                'suggested_action' => sprintf(
                    'Tick more periods for %s under Preferred Meeting in Configure, or clear them so it follows the section.',
                    $courseCode,
                ),
                'context' => [
                    'section_id' => (int) $section->id,
                    'section_name' => $sectionName,
                    'course_id' => (int) $course->id,
                    'course_code' => (string) ($course->course_code ?? ''),
                    'preferred_periods' => $periods,
                    'period_label' => $periodLabel,
                    'required_slots' => $required,
                    'available_slots_in_period' => $windowSlots,
                ],
            ];
        }

        $period = $periods[0];
        // Halving the meeting is the fix that keeps the period, so it is offered
        // ahead of widening -- including when the department has not turned the
        // matching split setting on yet, since the setting is the first step of
        // that fix rather than a reason to omit it.
        $splitSettings = SchedulingPolicy::balancedSplitSettings($department);
        $alreadyEligible = ! $isBalancedSplit
            && SchedulingPolicy::balancedSplitEligible($course, $splitSettings);
        $eligibleOnceEnabled = false;
        $halfFitsTheWindow = (int) ceil($required / 2) <= $windowSlots;

        if ($alreadyEligible && $halfFitsTheWindow) {
            $suggestion = sprintf(
                'Select Split Session for %s in Step 2 so it meets twice for half the time, or set %s back to Any time on the Preferred Meetings board.',
                $courseCode,
                $sectionName,
            );
        } else {
            $suggestion = sprintf(
                'Set %s back to Any time on the Preferred Meetings board, or move it to a period wide enough for a block of %s.',
                $sectionName,
                $this->describeHours($required),
            );
        }

        return [
            'code' => 'component_duration_exceeds_period',
            'message' => sprintf(
                '%s needs a block of %s, which does not fit %s: the %s period is only %s long.',
                $courseCode,
                $this->describeHours($required),
                $sectionName,
                $periodLabel,
                $this->describeHours($windowSlots),
            ),
            'suggested_action' => $suggestion,
            'context' => [
                'section_id' => (int) $section->id,
                'section_name' => $sectionName,
                'course_id' => (int) $course->id,
                'course_code' => (string) ($course->course_code ?? ''),
                'preferred_period' => $period,
                'period_label' => $periodLabel,
                'required_slots' => $required,
                'available_slots_in_period' => $windowSlots,
                'splittable' => $alreadyEligible && $halfFitsTheWindow,
                'splittable_once_enabled' => $eligibleOnceEnabled && $halfFitsTheWindow,
            ],
        ];
    }

    /**
     * Every meeting the generator must place for this course, as slot counts.
     *
     * One entry for an ordinary course, two for either kind of split -- and the
     * two halves of a split must land on different days, so a split trades a
     * longer meeting for an extra day.
     *
     * @return non-empty-list<int>
     */
    private function meetingSlotsForCourse(
        Course $course,
        bool $isLectureLabSplit,
        bool $isBalancedSplit,
        Departments $department,
        array $config = [],
    ): array {
        if ($isLectureLabSplit) {
            return array_values($this->integratedHybridSlots($course, $department, $config));
        }

        $total = $this->configuredSlots($course, $config);

        if ($isBalancedSplit) {
            // Balanced halves, the longer one first when the total is odd.
            return [(int) ceil($total / 2), (int) floor($total / 2)];
        }

        return [$total];
    }

    /**
     * A pinned section that cannot hold its own courses, whatever the rooms.
     *
     * This is the arithmetic the search stage could only discover by exhausting
     * itself. A period is about a third of the teaching day, and the generator
     * steps a meeting's legal start times by the meeting's own length, so a
     * 4.5-hour window admits exactly one start for a three-hour class. A pinned
     * section therefore gets one such class per day, and a curriculum with more
     * three-hour courses than there are teaching days cannot be pinned at all --
     * no room, split or retry changes that. The run used to spend its budget
     * proving this per section and then report only that no timetable fit.
     *
     * Rooms deliberately play no part: the bound is on the section's own
     * timetable, which is why it holds for online and field meetings too.
     *
     * @param  list<int>  $meetingSlots  every meeting the section must be given
     * @return array<string, mixed>|null
     */
    private function periodCapacityBlocker(
        Sections $section,
        string $period,
        array $meetingSlots,
        int $availableDays,
        int $courseCount,
    ): ?array {
        $meetingSlots = array_values(array_filter($meetingSlots, static fn (int $slots): bool => $slots > 0));
        if ($meetingSlots === [] || $availableDays <= 0) {
            return null;
        }

        [$from, $to] = SchedulingPolicy::preferredPeriodSlotRange($period);
        $perDay = $this->maxMeetingsPerDay($meetingSlots, $from, $to);

        // Zero means nothing fits at all, which periodWindowBlocker already
        // reported against the specific course.
        if ($perDay <= 0) {
            return null;
        }

        $requiredDays = (int) ceil(count($meetingSlots) / $perDay);
        if ($requiredDays <= $availableDays) {
            return null;
        }

        $sectionName = (string) $section->section_name;
        $periodLabel = SchedulingPolicy::preferredPeriodLabel($period);

        return [
            'code' => 'period_capacity_exceeded',
            'message' => sprintf(
                '%s cannot fit %d courses inside the %s period: that window holds only %s per day, so the %d meetings need %d teaching days and only %d are available.',
                $sectionName,
                $courseCount,
                $periodLabel,
                $perDay === 1 ? 'one meeting' : $perDay.' meetings',
                count($meetingSlots),
                $requiredDays,
                $availableDays,
            ),
            'suggested_action' => sprintf(
                'Set %s back to Any time on the Preferred Meetings board, or split its longer courses into two shorter sessions so more than one fits per day.',
                $sectionName,
            ),
            'context' => [
                'section_id' => (int) $section->id,
                'section_name' => $sectionName,
                'preferred_period' => $period,
                'period_label' => $periodLabel,
                'course_count' => $courseCount,
                'meeting_count' => count($meetingSlots),
                'meetings_per_day' => $perDay,
                'required_days' => $requiredDays,
                'available_days' => $availableDays,
            ],
        ];
    }

    /**
     * The most meetings from this set that can share one day inside the window.
     *
     * Exact rather than estimated, so the day bound above stays provable: it
     * searches the legal start grid the generator itself uses, placing meetings
     * left to right without overlap. The search is tiny -- a section has a
     * handful of distinct meeting lengths and a window offers a handful of
     * starts -- and it is memoised on the remaining counts.
     *
     * @param  list<int>  $meetingSlots
     */
    private function maxMeetingsPerDay(array $meetingSlots, int $from, int $to): int
    {
        $counts = array_count_values($meetingSlots);
        $startsByDuration = [];
        foreach (array_keys($counts) as $slots) {
            $startsByDuration[$slots] = array_values(array_filter(
                SchedulingPolicy::generatedStartSlotsForDuration((int) $slots),
                static fn (int $start): bool => $start >= $from && $start + (int) $slots <= $to,
            ));
        }

        $memo = [];
        $search = function (int $position, array $counts) use (&$search, &$memo, $startsByDuration, $to): int {
            $key = $position.'|'.implode(',', $counts);
            if (isset($memo[$key])) {
                return $memo[$key];
            }

            $best = 0;
            foreach ($counts as $slots => $remaining) {
                if ($remaining <= 0) {
                    continue;
                }

                foreach ($startsByDuration[$slots] as $start) {
                    if ($start < $position || $start + (int) $slots > $to) {
                        continue;
                    }

                    $next = $counts;
                    $next[$slots]--;
                    $best = max($best, 1 + $search($start + (int) $slots, $next));
                }
            }

            return $memo[$key] = $best;
        };

        return $search($from, $counts);
    }

    private function describeHours(int $slots): string
    {
        $hours = ($slots * SchedulingPolicy::SLOT_MINUTES) / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').' hours';
    }

    /**
     * A course pinned to one day can only be placed as many times as that day
     * has room for it. Pinning is per department and per course, so this reads
     * whatever has been configured and does nothing when nothing is pinned.
     *
     * The arithmetic is exact rather than heuristic. A course of a given
     * duration has a fixed set of legal start times (the generator steps the
     * start grid by the meeting length), and its room type has a fixed
     * concurrency. Multiply the two, subtract what other year levels already
     * hold on that day, and the result is the hard ceiling on how many sections
     * can take that course. Without this the solver discovers the shortfall one
     * section at a time and spends the whole run budget rediscovering it.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkForcedDayCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $forcedDays = SchedulingPolicy::forcedCourseDayMap((int) $department->id);
        if ($forcedDays === []) {
            return [];
        }

        $blocking = [];

        foreach ($forcedDays as $courseId => $day) {
            $course = $courses->get((int) $courseId);
            if ($course === null) {
                continue;
            }

            $demand = 0;
            foreach ($sections as $section) {
                $config = $configsBySectionId[(int) $section->id] ?? [];
                if (in_array((int) $courseId, array_map('intval', $config['course_ids'] ?? []), true)) {
                    $demand++;
                }
            }

            if ($demand === 0) {
                continue;
            }

            $durationSlots = $this->courseSlots($course);
            $startCount = count($this->legalStartSlots($course, $department, $durationSlots));
            if ($startCount === 0) {
                continue;
            }

            $capacity = $this->forcedDayConcurrency($course, $department);
            if ($capacity === null || $capacity <= 0) {
                continue;
            }

            $supply = ($startCount * $capacity) - $this->occupiedForcedDaySlots($sections, (int) $courseId, $day);
            if ($demand <= $supply) {
                continue;
            }

            $needed = (int) ceil($demand / max(1, $startCount));

            $blocking[] = [
                'code' => 'forced_day_capacity_exceeded',
                'message' => sprintf(
                    '%s is pinned to %s and needs %d placements, but %s offers only %d: %d start %s at a concurrency of %d.',
                    (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}"),
                    $day,
                    $demand,
                    $day,
                    max(0, $supply),
                    $startCount,
                    $startCount === 1 ? 'time' : 'times',
                    $capacity,
                ),
                'suggested_action' => sprintf(
                    'Make at least %d rooms of that type available, '
                    .'or release the %s pin so the course can spread across more days.',
                    $needed,
                    $day,
                ),
                'context' => [
                    'course_id' => (int) $courseId,
                    'course_code' => (string) ($course->course_code ?? ''),
                    'forced_day' => $day,
                    'required_placements' => $demand,
                    'available_placements' => max(0, $supply),
                    'start_times' => $startCount,
                    'concurrency' => $capacity,
                    'required_concurrency' => $needed,
                ],
            ];
        }

        return $blocking;
    }

    /**
     * Start slots the generator will actually offer this course.
     *
     * The start grid alone is not the answer: a field course must finish by the
     * institution's field end time, so a three-hour field course loses its late
     * start even though the grid lists it. Counting the grid without that rule overstates supply and lets an
     * impossible configuration through the pre-check.
     *
     * @return list<int>
     */
    private function legalStartSlots(Course $course, Departments $department, int $durationSlots): array
    {
        $startSlots = SchedulingPolicy::generatedStartSlotsForDuration($durationSlots);

        if (! SchedulingPolicy::isFieldCourse($course, (int) $department->id)) {
            return $startSlots;
        }

        $fieldEnd = SchedulingPolicy::fieldDayEndTime();

        return array_values(array_filter(
            $startSlots,
            static fn (int $slot): bool => SchedulingPolicy::slotToTime($slot + $durationSlots) <= $fieldEnd,
        ));
    }

    /**
     * How many sections can hold this course at the same time: one per usable
     * room of its type. Null for a field course -- the field is shared without
     * a limit, so a pinned day never runs out of room for it.
     */
    private function forcedDayConcurrency(Course $course, Departments $department): ?int
    {
        if (SchedulingPolicy::isFieldCourse($course, (int) $department->id)) {
            return null;
        }

        $roomType = SchedulingPolicy::isLaboratoryCourse($course) ? 'laboratory' : 'lecture';

        return $this->usableRooms($department, [$roomType])->count();
    }

    /**
     * Placements on the pinned day already held by schedules this run will not
     * replace, so a partly-scheduled semester is measured against what is left.
     *
     * @param  list<Sections>  $sections
     */
    private function occupiedForcedDaySlots(array $sections, int $courseId, string $day): int
    {
        $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);
        $semesterId = (int) ($sections[array_key_first($sections)]->semester_id ?? 0);

        return Schedule::query()
            ->where('semester_id', $semesterId)
            ->where('course_id', $courseId)
            ->where('day', $day)
            ->whereNotIn('section_id', $sectionIds)
            ->whereNotIn('status', self::REPLACEABLE_STATUSES)
            ->distinct()
            ->count('section_id');
    }

    /**
     * Total on-site slot demand against the department's usable lecture and
     * laboratory rooms for the week.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkPhysicalRoomCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
        int $slotsPerDay,
    ): array {
        $demand = 0;
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $demand += $this->onSiteSlotDemand(
                $config,
                $courses,
                (int) $section->department_id,
            );
        }

        if ($demand === 0) {
            // Nothing needs a physical room, so the absence of one is not a
            // shortfall — a fully online year level is a valid request.
            return [];
        }

        $rooms = $this->usableRooms($department, ['lecture', 'laboratory']);
        if ($rooms->isEmpty()) {
            return [[
                'code' => 'no_physical_rooms',
                'message' => 'The department has no available lecture or laboratory room.',
                'suggested_action' => 'Add or re-enable a physical room, or set the affected courses to Online.',
                'context' => ['required_room_types' => ['lecture', 'laboratory']],
            ]];
        }

        $supply = $this->weeklyRoomSlotSupply($rooms, $slotsPerDay)
            - $this->occupiedRoomSlots($sections, $rooms->pluck('id')->map('intval')->all());

        if ($demand <= $supply) {
            return [];
        }

        return [[
            'code' => 'insufficient_room_slots',
            'message' => sprintf(
                'On-site placements need %d room-slots but only %d are free across %d room%s.',
                $demand,
                max(0, $supply),
                $rooms->count(),
                $rooms->count() === 1 ? '' : 's',
            ),
            'suggested_action' => 'Set some courses to Online, reduce lecture/lab splitting, or free up existing draft schedules.',
            'context' => [
                'required_slots' => $demand,
                'available_slots' => max(0, $supply),
                'room_count' => $rooms->count(),
            ],
        ]];
    }

    /**
     * Fixed MW/TTh patterns concentrate demand onto two days, which is where a
     * year-level run most often becomes infeasible.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkFixedPatternCapacity(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        $rooms = $this->usableRooms($department, ['lecture', 'laboratory']);
        if ($rooms->isEmpty()) {
            return [];
        }

        $slotsPerDay = SchedulingPolicy::totalSlots();
        $demandByPattern = [];
        $sectionsByPattern = [];
        $targetsByPattern = [];

        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            foreach (($config['preferred_patterns'] ?? []) as $courseId => $pattern) {
                $days = SchedulingPolicy::allowedDaysForPattern($pattern);
                if ($days === null || $days === []) {
                    continue;
                }

                $course = $courses->get((int) $courseId);
                if ($course === null || ($config['delivery_modes_by_course_id'][$courseId] ?? null) === 'online') {
                    continue;
                }

                $key = (string) $pattern;
                // A pattern splits the course across its days, so each day carries
                // roughly half the course's slots.
                $demandByPattern[$key] = ($demandByPattern[$key] ?? 0)
                    + (int) ceil($this->configuredSlots($course, $config) / count($days));
                $sectionsByPattern[$key][(int) $section->id] = (string) $section->section_name;
                $targetsByPattern[$key][] = [
                    'section_id' => (int) $section->id,
                    'section_name' => (string) $section->section_name,
                    'course_id' => (int) $courseId,
                    'course_code' => (string) ($course->course_code ?? ('Course '.$courseId)),
                ];
            }
        }

        $blocking = [];
        foreach ($demandByPattern as $pattern => $perDayDemand) {
            $supplyPerDay = $this->concurrentRoomCapacity($rooms) * $slotsPerDay;
            if ($perDayDemand <= $supplyPerDay) {
                continue;
            }

            $blocking[] = [
                'code' => 'fixed_pattern_overloaded',
                'message' => sprintf(
                    'The %s pattern needs %d room-slots on each of its days, but only %d are available per day.',
                    $pattern,
                    $perDayDemand,
                    $supplyPerDay,
                ),
                'suggested_action' => sprintf(
                    'Move some %s courses to the other pattern, or let the generator choose the days automatically.',
                    $pattern,
                ),
                'context' => [
                    'pattern' => $pattern,
                    'required_slots_per_day' => $perDayDemand,
                    'available_slots_per_day' => $supplyPerDay,
                    'sections' => array_values($sectionsByPattern[$pattern] ?? []),
                    'section_ids' => array_map('intval', array_keys($sectionsByPattern[$pattern] ?? [])),
                    'targets' => array_values($targetsByPattern[$pattern] ?? []),
                ],
            ];
        }

        return $blocking;
    }

    /** @param  list<Sections>  $sections */
    private function resolveDepartment(array $sections): Departments
    {
        $section = $sections[array_key_first($sections)];

        return $section->department ?: Departments::query()->findOrFail((int) $section->department_id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @return Collection<int, Course>
     */
    private function courses(array $configsBySectionId): Collection
    {
        $courseIds = [];
        foreach ($configsBySectionId as $config) {
            foreach (($config['course_ids'] ?? []) as $courseId) {
                $courseIds[(int) $courseId] = (int) $courseId;
            }
        }

        if ($courseIds === []) {
            return collect();
        }

        return Course::query()
            ->whereIn('id', array_values($courseIds))
            ->get()
            ->keyBy(static fn (Course $course): int => (int) $course->id);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  Collection<int, Course>  $courses
     * @return list<Course>
     */
    private function configuredCourses(array $config, Collection $courses): array
    {
        $selected = [];
        foreach (($config['course_ids'] ?? []) as $courseId) {
            $course = $courses->get((int) $courseId);
            if ($course !== null) {
                $selected[] = $course;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  Collection<int, Course>  $courses
     */
    private function onSiteSlotDemand(
        array $config,
        Collection $courses,
        int $departmentId,
    ): int
    {
        $slots = 0;
        $hybridSplitIds = array_map('intval', $config['hybrid_split_course_ids'] ?? []);
        foreach ($this->configuredCourses($config, $courses) as $course) {
            $mode = $config['delivery_modes_by_course_id'][(int) $course->id] ?? null;
            if ($mode === 'online') {
                continue;
            }
            if (in_array((int) $course->id, $hybridSplitIds, true)) {
                $slots += SchedulingPolicy::hybridSplitMeetingSlots();
                continue;
            }
            if (SchedulingPolicy::isLaboratoryCourse($course)) {
                // Laboratory placements may use Room TBA when no compatible
                // laboratory slot is available; do not hard-block generation.
                continue;
            }
            if ($mode === 'field' || SchedulingPolicy::isFieldCourse($course, $departmentId)) {
                continue;
            }

            $slots += $this->configuredSlots($course, $config);
        }

        return $slots;
    }

    /**
     * The weekly slots this section will actually spend on the course: its
     * Setup Courses Custom Time Duration when one was chosen, otherwise the
     * contact-hour estimate below.
     *
     * @param  array<string, mixed>  $config
     */
    private function configuredSlots(Course $course, array $config): int
    {
        return CourseSetupOverrides::durationSlots($config, (int) $course->id)
            ?? $this->courseSlots($course);
    }

    /**
     * Integrated Hybrid's two sessions: the lengths chosen in Setup Courses,
     * else the course's own.
     *
     * @param  array<string, mixed>  $config
     * @return array{lecture: int, laboratory: int}
     */
    private function integratedHybridSlots(Course $course, Departments $department, array $config): array
    {
        $courseId = (int) $course->id;

        return [
            'lecture' => CourseSetupOverrides::componentSlots($config, $courseId, 'lecture')
                ?? SchedulingPolicy::lectureComponentSlots($course),
            'laboratory' => CourseSetupOverrides::componentSlots($config, $courseId, 'laboratory')
                ?? SchedulingPolicy::laboratoryComponentSlots($course, $department),
        ];
    }

    /** Slot count a course occupies for the week, derived from its contact hours. */
    private function courseSlots(Course $course): int
    {
        // The Generator's single block is `units × 2` slots. Summing
        // `lecture_hours + lab_hours` as hours read laboratory units as one
        // hour each, when one laboratory unit meets for three.
        $units = (float) ($course->units ?? 0);
        if ($units <= 0) {
            $units = (float) ($course->lecture_hours ?? 0) + (float) ($course->lab_hours ?? 0);
        }

        return max(1, (int) ceil(SchedulingPolicy::unitMinutes($units) / SchedulingPolicy::SLOT_MINUTES));
    }

    /**
     * Room-slots already committed by schedules this run will not replace.
     *
     * @param  list<Sections>  $sections
     * @param  list<int>  $roomIds
     */
    private function occupiedRoomSlots(array $sections, array $roomIds): int
    {
        if ($roomIds === []) {
            return 0;
        }

        $semesterIds = array_values(array_unique(array_map(
            static fn (Sections $section): int => (int) $section->semester_id,
            $sections,
        )));
        $sectionIds = array_map(static fn (Sections $section): int => (int) $section->id, $sections);

        $minutes = Schedule::query()
            ->whereIn('room_id', $roomIds)
            ->whereIn('semester_id', $semesterIds)
            ->where(function ($query) use ($sectionIds): void {
                $query->whereNotIn('section_id', $sectionIds)
                    ->orWhereNotIn('status', self::REPLACEABLE_STATUSES);
            })
            ->get(['section_id', 'course_id', 'room_id', 'day', 'start_time', 'end_time', 'mode', 'status'])
            ->unique(static fn (Schedule $schedule): string => implode('|', [
                (int) $schedule->section_id,
                (int) $schedule->course_id,
                (int) $schedule->room_id,
                (string) $schedule->day,
                (string) $schedule->start_time,
                (string) $schedule->end_time,
                (string) $schedule->mode,
                (string) $schedule->status,
            ]))
            ->sum(function (Schedule $schedule): int {
                $start = SchedulingPolicy::timeToMinutes((string) $schedule->start_time);
                $end = SchedulingPolicy::timeToMinutes((string) $schedule->end_time);

                return max(0, $end - $start);
            });

        return (int) floor($minutes / SchedulingPolicy::SLOT_MINUTES);
    }

    /**
     * Weekly room-slot supply, counting a room's configured concurrency so a
     * shared room is not undercounted into a false shortfall.
     *
     * @param  Collection<int, Rooms>  $rooms
     */
    private function weeklyRoomSlotSupply(Collection $rooms, int $slotsPerDay): int
    {
        return $this->concurrentRoomCapacity($rooms)
            * $slotsPerDay
            * SchedulingPolicy::countAllowedDays(SchedulingPolicy::WEEKDAYS_AND_SATURDAY, $this->allowedDays);
    }

    /**
     * Provable conflicts with Step 1's Preferred Days, named per course.
     *
     * Both Hybrid shapes are two meetings on two different days, so they need
     * at least two allowed days. A Split Session can fall back to one meeting,
     * so it is left to the solver, as is a course whose own day limits (field
     * weekdays, Sunday rules) leave it nothing among the chosen days -- the
     * solver names that course and the Preferred Days in its error.
     *
     * @param  list<Sections>  $sections
     * @param  array<int, array<string, mixed>>  $configsBySectionId
     * @param  Collection<int, Course>  $courses
     * @return list<array<string, mixed>>
     */
    private function checkPreferredDays(
        array $sections,
        array $configsBySectionId,
        Collection $courses,
        Departments $department,
    ): array {
        if ($this->allowedDays === null) {
            return [];
        }

        $blocking = [];
        $reported = [];
        $dayList = implode(', ', $this->allowedDays);
        foreach ($sections as $section) {
            $config = $configsBySectionId[(int) $section->id] ?? [];
            $twoDayIds = array_map('intval', [
                ...($config['selected_split_session_course_ids'] ?? []),
                ...($config['hybrid_split_course_ids'] ?? []),
            ]);

            foreach ($this->configuredCourses($config, $courses) as $course) {
                $courseId = (int) $course->id;
                if (isset($reported[$courseId]) || ! in_array($courseId, $twoDayIds, true) || count($this->allowedDays) >= 2) {
                    continue;
                }

                $reported[$courseId] = true;
                $code = (string) ($course->course_code ?? $course->course_name ?? "Course {$courseId}");
                $blocking[] = [
                    'code' => 'preferred_days_too_few_for_hybrid',
                    'message' => sprintf(
                        '%s is Hybrid, which meets on two different days, but the Preferred Days allow only %s.',
                        $code,
                        $dayList,
                    ),
                    'suggested_action' => sprintf(
                        'Add another Preferred Day in Configuration, or set %s back to On-Site in Setup Courses.',
                        $code,
                    ),
                    'context' => [
                        'course_id' => $courseId,
                        'course_code' => $code,
                        'allowed_days' => $this->allowedDays,
                    ],
                ];
            }
        }

        return $blocking;
    }

    /**
     * Classes the rooms can hold at once: a lecture or laboratory room holds one.
     *
     * @param  Collection<int, Rooms>  $rooms
     */
    private function concurrentRoomCapacity(Collection $rooms): int
    {
        return $rooms->count();
    }

    /**
     * @param  list<string>  $roomTypes
     * @return Collection<int, Rooms>
     */
    private function usableRooms(Departments $department, array $roomTypes): Collection
    {
        return Rooms::query()
            ->whereIn('room_type', $roomTypes)
            // A granted room counts in full even though it is open only in its
            // windows: overstating supply never refuses a feasible run.
            ->tap(fn ($query) => app(RoomAccessPolicy::class)->scopeReachableRooms($query, (int) $department->id, $this->semesterId))
            ->where(function ($query): void {
                $query->where('status', 'available')->orWhereNull('status');
            })
            ->get(['id', 'room_code', 'room_type']);
    }
}
