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

    /** Grid start: 07:00 AM in minutes from midnight. */
    private const GRID_START_MINUTES = SchedulingPolicy::OPERATING_START_MINUTES;

    /** Grid end: 07:00 PM = 19:00 in minutes from midnight. */
    private const GRID_END_MINUTES = 19 * 60;

    /** Total 30-min slots in the operating window: 24. */
    private const TOTAL_SLOTS = SchedulingPolicy::TOTAL_SLOTS;

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
        if ($durationSlots < 1 || $durationSlots > self::TOTAL_SLOTS) {
            throw new InvalidArgumentException(
                "durationSlots must be between 1 and " . self::TOTAL_SLOTS . ", got {$durationSlots}."
            );
        }

        $course  = Course::findOrFail($courseId);
        $section = Sections::with('term')->findOrFail($sectionId);

        $targetRoomType = match (true) {
            $mode === 'online' => 'online',
            $mode === 'field'  => 'field',
            $meetingType === 'lecture' => 'lecture',
            $meetingType === 'laboratory' => 'laboratory',
            default            => (string) ($course->room_type_required ?: 'lecture'),
        };

        // Build the list of rooms to search over.
        $rooms = $this->resolveRooms($course, $mode, $roomId, $departmentId, $meetingType);

        if ($rooms->isEmpty()) {
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
                $candidate['score'] = $this->scoreCandidate($candidate, $roomId, $mode, $preferredDay, $targetRoomType);
                $validCandidates[]  = $candidate;
            }
        }

        if (empty($validCandidates)) {
            return ['status' => 'no_solution', 'recommendations' => []];
        }

        // Sort by score descending and pick top N.
        usort($validCandidates, static fn ($a, $b): int => $b['score'] <=> $a['score']);

        $ranked = [];
        foreach (array_slice($validCandidates, 0, $maxResults) as $index => $candidate) {
            $room = $rooms->firstWhere('id', $candidate['room_id']);
            $ranked[] = [
                'rank'       => $index + 1,
                'score'      => $candidate['score'],
                'day'        => $candidate['day'],
                'start_time' => $candidate['start_time'],
                'end_time'   => $candidate['end_time'],
                'room_id'    => $candidate['room_id'],
                'room_name'  => $room?->room_code ?? ('Room ' . $candidate['room_id']),
                'room_type'  => $room?->room_type ?? 'lecture',
                'mode'       => $candidate['mode'],
            ];
        }

        return ['status' => 'ok', 'recommendations' => $ranked];
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

        // For lab courses, also include lecture rooms as fallback.
        if ($targetRoomType === 'laboratory') {
            $lectureRooms = Rooms::query()
                ->where('status', 'available')
                ->where('room_type', 'lecture')
                ->where(static function ($q) use ($departmentId): void {
                    $q->whereNull('department_id')
                      ->orWhere('department_id', $departmentId);
                })
                ->orderBy('room_code')
                ->get();
            $rooms = $rooms->merge($lectureRooms);
        }

        // For lecture courses, also include lab rooms as fallback.
        if ($targetRoomType === 'lecture') {
            $labRooms = Rooms::query()
                ->where('status', 'available')
                ->where('room_type', 'laboratory')
                ->where(static function ($q) use ($departmentId): void {
                    $q->whereNull('department_id')
                      ->orWhere('department_id', $departmentId);
                })
                ->orderBy('room_code')
                ->get();
            $rooms = $rooms->merge($labRooms);
        }

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
    ): array {
        $latestStart = self::TOTAL_SLOTS - $durationSlots;

        $days = SchedulingPolicy::WEEKDAYS_AND_SATURDAY;
        if ($preferredDay !== null) {
            $days = array_values(array_unique(array_merge([$preferredDay], $days)));
        }

        $candidates = [];

        foreach ($days as $day) {
            for ($startSlot = 0; $startSlot <= $latestStart; $startSlot++) {
                $endSlot   = $startSlot + $durationSlots;
                $startTime = $this->slotToTime($startSlot);
                $endTime   = $this->slotToTime($endSlot);

                if ($preferredStartTime !== null && $startTime !== $preferredStartTime) {
                    continue;
                }

                foreach ($rooms as $room) {
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
    ): int {
        $score = 100;

        // Prefer weekdays over Saturday.
        if ($candidate['day'] === 'Saturday') {
            $score -= 8;
        }

        // Prefer earlier start times.
        $startMinutes = $this->timeToMinutes($candidate['start_time']);
        $afterSlot22  = self::GRID_START_MINUTES + (22 * self::SLOT_MINUTES); // ~18:00
        if ($startMinutes > $afterSlot22) {
            $score -= 4;
        }

        // Prefer the originally requested room.
        if ($preferredRoomId !== null && $candidate['room_id'] === $preferredRoomId) {
            $score += 10;
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
            $score += 20;
        }

        return $score;
    }

    private function slotToTime(int $slot): string
    {
        $minutes = self::GRID_START_MINUTES + ($slot * self::SLOT_MINUTES);
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function timeToMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }
}
