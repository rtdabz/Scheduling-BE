<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Course;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use InvalidArgumentException;

/**
 * Resolves a single split-session placement using the CSP solver and Rule Engine.
 *
 * The caller provides one split block's context (course, section, term, duration,
 * preferred day/time/room/mode, and any schedule IDs to treat as deleted).
 * This service builds a focused CSP domain for only that one block, pre-loads
 * all existing persisted schedules for the section as constraints, then searches
 * for the top N conflict-free (day, time, room, mode) combinations ranked by
 * preference score.
 */
final class SplitScheduleService
{
    /** Slot duration in minutes. */
    private const SLOT_MINUTES = SchedulingPolicy::SLOT_MINUTES;

    public function __construct(
        private readonly RuleEngine $ruleEngine,
    ) {}

    /**
     * Find the best conflict-free placements for a single split-session block.
     *
     * @param  int          $termId         Academic term ID.
     * @param  int          $sectionId      Section being scheduled.
     * @param  int          $courseId       Course being split.
     * @param  int          $departmentId   Department owning the section.
     * @param  int          $durationSlots  Number of 30-min slots for this block.
     * @param  int|null     $roomId         Preferred room ID (null = any).
     * @param  string       $mode           Preferred delivery mode.
     * @param  int|null     $facultyId      Assigned faculty (null = none).
     * @param  int[]        $deleteIds      Schedule IDs to treat as removed.
     * @param  int          $maxResults     Maximum number of results to return.
     * @param  float        $timeoutSeconds CSP time limit in seconds.
     * @return array{status: string, recommendations: array<int, array<string, mixed>>}
     */
    public function recommend(
        int    $termId,
        int    $sectionId,
        int    $courseId,
        int    $departmentId,
        int    $durationSlots,
        ?int   $roomId,
        string $mode,
        ?int   $facultyId,
        array  $deleteIds,
        int    $maxResults = 5,
        float  $timeoutSeconds = 5.0,
        ?string $meetingType = null,
        ?string $preferredDay = null,
        ?string $preferredStartTime = null,
    ): array {
        if ($durationSlots < 1 || $durationSlots > SchedulingPolicy::totalSlots()) {
            throw new InvalidArgumentException(
                "durationSlots must be between 1 and " . SchedulingPolicy::totalSlots() . ", got {$durationSlots}."
            );
        }

        $course  = Course::findOrFail($courseId);
        $section = Sections::with('term')->findOrFail($sectionId);
        $meetingType ??= $this->inferMeetingType($course, $durationSlots);

        $targetRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field'  => 'field',
            $meetingType === 'lecture' => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            default            => (string) ($course->room_type_required ?: 'lecture'),
        };

        // Build the list of rooms to search over.
        $rooms = $this->resolveRooms($course, $mode, $roomId, $departmentId, $meetingType);

        $allowRoomTba = $mode === 'on-site'
            && SchedulingPolicy::allowsRoomTbaFallback($course, $meetingType);

        if ($rooms->isEmpty() && ! $allowRoomTba) {
            return ['status' => 'no_solution', 'recommendations' => []];
        }

        // Build every (day, startSlot, room) candidate for this block.
        $candidates = $this->buildCandidates(
            course:             $course,
            rooms:              $rooms,
            durationSlots:      $durationSlots,
            mode:               $mode,
            preferredDay:       $preferredDay,
            preferredStartTime: $preferredStartTime,
            allowRoomTba: $allowRoomTba,
        );

        if (empty($candidates)) {
            return ['status' => 'no_solution', 'recommendations' => []];
        }

        // Filter only conflict-free candidates using the Rule Engine.
        $ignoreIds      = array_values(array_unique(array_map('intval', $deleteIds)));
        $startedAt      = microtime(true);
        $validCandidates = [];

        foreach ($candidates as $candidate) {
            if ((microtime(true) - $startedAt) >= $timeoutSeconds) {
                break;
            }

            if ($this->candidateHasPersistedConflict($candidate, $termId, $sectionId, $ignoreIds)) {
                continue;
            }

            $data = [
                'term_id'             => $termId,
                'section_id'          => $sectionId,
                'course_id'           => $courseId,
                'room_id'             => $candidate['room_id'],
                'department_id'       => $departmentId,
                'day'                 => $candidate['day'],
                'start_time'          => $candidate['start_time'],
                'end_time'            => $candidate['end_time'],
                'mode'                => $candidate['mode'],
                'is_hybrid'           => false,
                'faculty_id'          => $facultyId,
                'ignore_schedule_id'  => $ignoreIds,
                'meeting_type'        => $meetingType,
            ];

            $violations = $this->ruleEngine->validate($data);

            if (empty($violations)) {
                $candidate['score'] = $this->scoreCandidate($candidate, $roomId, $mode, $preferredDay, $targetRoomType, $preferredStartTime);
                $validCandidates[]  = $candidate;
            }
        }

        if (empty($validCandidates)) {
            return ['status' => 'no_solution', 'recommendations' => []];
        }

        if ($preferredStartTime !== null) {
            $sameTimeCandidates = array_values(array_filter(
                $validCandidates,
                static fn (array $candidate): bool =>
                    substr((string) $candidate['start_time'], 0, 5) === substr($preferredStartTime, 0, 5),
            ));

            if ($sameTimeCandidates !== []) {
                $validCandidates = $sameTimeCandidates;
            }
        }

        // Sort by score descending and pick top N.
        usort($validCandidates, static fn ($a, $b): int => $b['score'] <=> $a['score']);

        $ranked = [];
        foreach (array_slice($validCandidates, 0, $maxResults) as $index => $candidate) {
            $room = $candidate['room_id'] !== null ? $rooms->firstWhere('id', $candidate['room_id']) : null;
            $ranked[] = [
                'rank'       => $index + 1,
                'score'      => $candidate['score'],
                'day'        => $candidate['day'],
                'start_time' => $candidate['start_time'],
                'end_time'   => $candidate['end_time'],
                'room_id'    => $candidate['room_id'],
                'room_name'  => $room?->room_code ?? 'Room TBA',
                'room_type'  => $room?->room_type ?? 'laboratory',
                'mode'       => $candidate['mode'],
            ];
        }

        return ['status' => 'ok', 'recommendations' => $ranked];
    }

    private function candidateHasPersistedConflict(array $candidate, int $termId, int $sectionId, array $ignoreIds): bool
    {
        $schedules = Schedule::query()
            ->where('term_id', $termId)
            ->where('day', $candidate['day'])
            ->where(function ($query) use ($candidate, $sectionId): void {
                $query
                    ->where('room_id', (int) $candidate['room_id'])
                    ->orWhere('section_id', $sectionId);
            })
            ->when($ignoreIds !== [], fn ($q) => $q->whereNotIn('id', $ignoreIds))
            ->get(['start_time', 'end_time']);

        $candidateStart = $this->timeToMinutes((string) $candidate['start_time']);
        $candidateEnd = $this->timeToMinutes((string) $candidate['end_time']);

        foreach ($schedules as $schedule) {
            if (
                $candidateStart < $this->timeToMinutes((string) $schedule->end_time)
                && $this->timeToMinutes((string) $schedule->start_time) < $candidateEnd
            ) {
                return true;
            }
        }

        return false;
    }

    private function inferMeetingType(Course $course, int $durationSlots): ?string
    {
        $lectureSlots = max(0, (int) ($course->lecture_hours ?? 0) * 2);
        $labSlots = max(0, (int) ($course->lab_hours ?? 0) * 6);

        if ($lectureSlots > 0 && $durationSlots === $lectureSlots) {
            return 'lecture';
        }

        if ($labSlots > 0 && $durationSlots === $labSlots) {
            return 'laboratory';
        }

        return null;
    }

    /**
     * Load all rooms eligible for this split block.
     * Preferred room is tried first; if not usable, fall back to all eligible rooms.
     */
    private function resolveRooms(
        Course $course,
        string $mode,
        ?int   $preferredRoomId,
        int    $departmentId,
        ?string $meetingType = null,
    ): \Illuminate\Database\Eloquent\Collection {
        $targetRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field'  => 'field',
            $meetingType === 'lecture' => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            default            => (string) ($course->room_type_required ?: 'lecture'),
        };

        $query = Rooms::query()
            ->where('status', 'available')
            ->where('room_type', $targetRoomType)
            ->where(static function ($q) use ($departmentId): void {
                $q->whereNull('department_id')
                  ->orWhere('department_id', $departmentId);
            })
            ->orderBy('room_code');

        $rooms = $query->get();

        // Promote the preferred room to the front.
        if ($preferredRoomId !== null) {
            $rooms = $rooms->sortByDesc(
                static fn (Rooms $r): int => (int) ($r->id === $preferredRoomId)
            )->values();
        }

        return $rooms;
    }

    /**
     * Build all (day, startSlot, room, mode) candidates ordered by preference:
     * earlier slots before later, weekdays before weekends.
     *
     * @return array<int, array{day: string, start_time: string, end_time: string, room_id: int, mode: string, room_type: string}>
     */
    private function buildCandidates(
        Course     $course,
        \Illuminate\Database\Eloquent\Collection $rooms,
        int        $durationSlots,
        string     $mode,
        ?string    $preferredDay = null,
        ?string    $preferredStartTime = null,
        bool       $allowRoomTba = false,
    ): array {
        $startSlots = SchedulingPolicy::generatedStartSlotsForDuration($durationSlots);

        $days = SchedulingPolicy::WEEKDAYS_AND_SATURDAY;
        if ($preferredDay !== null) {
            $days = array_values(array_unique(array_merge([$preferredDay], $days)));
        }

        $candidates = [];

        $roomOptions = $rooms->all();
        if ($allowRoomTba) {
            // TBA is a last-resort candidate. Real rooms remain first so they
            // win normal scoring, but the recommendation still has a valid
            // result when every compatible laboratory room is occupied.
            $roomOptions[] = null;
        }
        foreach ($days as $day) {
            foreach ($startSlots as $startSlot) {
                $endSlot   = $startSlot + $durationSlots;
                $startTime = substr($this->slotToTime($startSlot), 0, 5);
                $endTime   = substr($this->slotToTime($endSlot), 0, 5);

                foreach ($roomOptions as $room) {
                    if ($room === null) {
                        $candidates[] = [
                            'day' => $day,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'room_id' => null,
                            'mode' => 'on-site',
                            'room_type' => 'laboratory',
                        ];
                        continue;
                    }
                    $candidateMode     = $mode;
                    $candidateRoomType = (string) $room->room_type;

                    // Hard: enforce mode/room-type alignment.
                    $modeRoomMismatch = match ($candidateMode) {
                        'online' => $candidateRoomType !== 'online',
                        'field'  => $candidateRoomType !== 'field',
                        default  => in_array($candidateRoomType, ['online', 'field'], true),
                    };

                    if ($modeRoomMismatch) {
                        continue;
                    }

                    $candidates[] = [
                        'day'        => $day,
                        'start_time' => $startTime,
                        'end_time'   => $endTime,
                        'room_id'    => (int) $room->id,
                        'mode'       => $candidateMode,
                        'room_type'  => $candidateRoomType,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * Assign a preference score to a conflict-free candidate.
     * Higher is better. Used to rank results before returning them.
     */
    private function scoreCandidate(
        array  $candidate,
        ?int   $preferredRoomId,
        string $preferredMode,
        ?string $preferredDay = null,
        ?string $preferredRoomType = null,
        ?string $preferredStartTime = null,
    ): int {
        $score = 100;

        // Prefer weekdays over Saturday.
        if ($candidate['day'] === 'Saturday') {
            $score -= 8;
        }

        // Prefer earlier start times.
        $startMinutes = $this->timeToMinutes($candidate['start_time']);
        $afterSlot22  = SchedulingPolicy::timeToMinutes(SchedulingPolicy::openingTime()) + (22 * self::SLOT_MINUTES);
        if ($startMinutes > $afterSlot22) {
            $score -= 4;
        }

        // Prefer the originally requested room.
        if ($preferredRoomId !== null && $candidate['room_id'] === $preferredRoomId) {
            $score += 5;
        }

        // Prefer the preferred room type.
        if ($preferredRoomType !== null && $candidate['room_type'] !== $preferredRoomType) {
            $score -= 30;
        }

        // Prefer the originally requested delivery mode.
        if ($candidate['mode'] === $preferredMode) {
            $score += 5;
        }

        // Prefer the originally requested day.
        if ($preferredDay !== null && $candidate['day'] === $preferredDay) {
            $score += 40;
        }

        if (
            $preferredStartTime !== null
            && substr((string) $candidate['start_time'], 0, 5) === substr($preferredStartTime, 0, 5)
        ) {
            $score += 20;
        }

        return $score;
    }

    private function slotToTime(int $slot): string
    {
        return SchedulingPolicy::slotToTime($slot);
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }
}
