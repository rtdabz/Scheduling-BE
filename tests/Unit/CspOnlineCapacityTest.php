<?php

namespace Tests\Unit;

use App\Services\Scheduling\Engine\CspSolver;
use App\Services\Scheduling\Department\DepartmentResourceSlotLimitService;
use App\Models\Rooms;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CspOnlineCapacityTest extends TestCase
{
    public function test_recursive_assignments_cannot_exceed_department_online_capacity(): void
    {
        $limits = new class extends DepartmentResourceSlotLimitService {
            public function online(int $departmentId): int
            {
                return 2;
            }
        };
        $solver = new CspSolver($limits);
        $conflicts = new ReflectionMethod($solver, 'conflictsWithTentativeAssignments');

        $candidate = $this->onlineCandidate(2, 4);
        $assignments = [
            $this->onlineAssignment(1, 0, 4),
            $this->onlineAssignment(2, 1, 3),
        ];

        self::assertTrue($conflicts->invoke(
            $solver,
            $candidate,
            $assignments,
            10,
            7,
        ));
    }

    public function test_recursive_assignments_allow_online_slot_when_capacity_remains(): void
    {
        $limits = new class extends DepartmentResourceSlotLimitService {
            public function online(int $departmentId): int
            {
                return 2;
            }
        };
        $solver = new CspSolver($limits);
        $conflicts = new ReflectionMethod($solver, 'conflictsWithTentativeAssignments');

        self::assertFalse($conflicts->invoke(
            $solver,
            $this->onlineCandidate(4, 6),
            [$this->onlineAssignment(1, 0, 4)],
            10,
            7,
        ));
    }

    public function test_non_hybrid_lecture_lab_options_exhaust_lecture_rooms_before_online(): void
    {
        $solver = new CspSolver;
        $method = new ReflectionMethod($solver, 'splitLectureOptions');
        $options = $method->invoke(
            $solver,
            new Collection([
                new Rooms(['id' => 12, 'room_type' => 'lecture']),
                new Rooms(['id' => 13, 'room_type' => 'laboratory']),
            ]),
            false,
            false,
        );

        self::assertSame('on-site', $options[0]['mode']);
        self::assertSame('lecture', $options[0]['room_type']);
        self::assertSame('online', $options[array_key_last($options)]['mode']);
        self::assertTrue($options[array_key_last($options)]['_lecture_online_fallback']);
    }

    public function test_hybrid_lecture_lab_options_keep_online_lecture_configuration(): void
    {
        $solver = new CspSolver;
        $method = new ReflectionMethod($solver, 'splitLectureOptions');
        $options = $method->invoke($solver, new Collection, true, false);

        self::assertCount(1, $options);
        self::assertSame('online', $options[0]['mode']);
        self::assertFalse($options[0]['_lecture_online_fallback']);
    }

    public function test_non_hybrid_online_lecture_fallback_is_ranked_after_physical_split(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');
        $physical = [
            'mode' => 'on-site',
            'is_hybrid' => false,
            '_split_lecture_online_default' => true,
            'blocks' => [
                ['mode' => 'on-site', 'room_type' => 'lecture', 'meeting_type' => 'lecture'],
                ['mode' => 'on-site', 'room_type' => 'laboratory', 'meeting_type' => 'laboratory'],
            ],
        ];
        $online = [
            'mode' => 'online',
            'is_hybrid' => false,
            '_split_lecture_online_default' => true,
            'blocks' => [
                ['mode' => 'online', 'room_type' => 'online', 'meeting_type' => 'lecture'],
                ['mode' => 'on-site', 'room_type' => 'laboratory', 'meeting_type' => 'laboratory'],
            ],
        ];

        self::assertLessThan(
            $priority->invoke($solver, $online, 1),
            $priority->invoke($solver, $physical, 1),
        );
    }

    public function test_minor_split_slot_search_keeps_all_ranked_start_pairs(): void
    {
        $solver = new CspSolver;
        $pairs = new ReflectionMethod($solver, 'rankedSplitStartPairs');

        $result = $pairs->invoke($solver, [0, 2, 4, 6], [0, 2, 4, 6], null);

        self::assertCount(16, $result);
        self::assertContains([6, 6], $result);
    }

    public function test_lecture_lab_pair_search_can_remain_bounded(): void
    {
        $solver = new CspSolver;
        $pairs = new ReflectionMethod($solver, 'rankedSplitStartPairs');

        self::assertCount(6, $pairs->invoke($solver, [0, 2, 4, 6], [0, 2, 4, 6], 6));
    }

    public function test_unconfigured_minor_physical_slot_is_ranked_before_online(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $physical = [
            'mode' => 'on-site',
            'room_type' => 'lecture',
            'room_id' => 12,
            'blocks' => [[
                'mode' => 'on-site',
                'room_type' => 'lecture',
                'meeting_type' => 'lecture',
            ]],
        ];
        $online = [
            'mode' => 'online',
            'room_type' => 'online',
            'blocks' => [[
                'mode' => 'online',
                'room_type' => 'online',
                'meeting_type' => 'lecture',
            ]],
        ];

        self::assertLessThan(
            $priority->invoke($solver, $online, 1),
            $priority->invoke($solver, $physical, 1),
        );
    }

    public function test_configured_minor_split_is_ranked_before_single_meeting_then_online(): void
    {
        $solver = new CspSolver;
        $priority = new ReflectionMethod($solver, 'candidateAllocationPriority');

        $splitPhysical = [
            'mode' => 'on-site',
            'room_type' => 'lecture',
            'blocks' => [
                ['mode' => 'on-site', 'room_type' => 'lecture', 'meeting_type' => 'lecture'],
                ['mode' => 'on-site', 'room_type' => 'lecture', 'meeting_type' => 'lecture'],
            ],
        ];
        $singlePhysical = [
            'mode' => 'on-site',
            'room_type' => 'lecture',
            '_single_session_fallback' => true,
            'blocks' => [[
                'mode' => 'on-site',
                'room_type' => 'lecture',
                'meeting_type' => 'lecture',
            ]],
        ];
        $singleOnline = [
            'mode' => 'online',
            'room_type' => 'online',
            '_single_session_fallback' => true,
            'blocks' => [[
                'mode' => 'online',
                'room_type' => 'online',
                'meeting_type' => 'lecture',
            ]],
        ];

        $splitPriority = $priority->invoke($solver, $splitPhysical, 1);
        $singlePhysicalPriority = $priority->invoke($solver, $singlePhysical, 1);
        $singleOnlinePriority = $priority->invoke($solver, $singleOnline, 1);

        self::assertLessThan($singlePhysicalPriority, $splitPriority);
        self::assertLessThan($singleOnlinePriority, $singlePhysicalPriority);
    }

    public function test_hybrid_physical_lab_candidates_are_exhausted_before_room_tba(): void
    {
        $solver = new CspSolver;
        $groups = new ReflectionMethod($solver, 'candidateGroupsByDayPriority');

        $tba = [
            'mode' => 'online',
            'room_type' => 'online',
            '_split_lecture_online_default' => true,
            '_room_tba' => true,
            'is_hybrid' => true,
            'blocks' => [
                ['day' => 'Monday', 'mode' => 'online', 'meeting_type' => 'lecture'],
                ['day' => 'Tuesday', 'mode' => 'on-site', 'room_type' => 'laboratory', 'meeting_type' => 'laboratory'],
            ],
        ];
        $physical = [
            'mode' => 'online',
            'room_type' => 'online',
            'room_id' => null,
            '_split_lecture_online_default' => true,
            'is_hybrid' => true,
            'blocks' => [
                ['day' => 'Monday', 'mode' => 'online', 'meeting_type' => 'lecture'],
                ['day' => 'Wednesday', 'mode' => 'on-site', 'room_id' => 41, 'room_type' => 'laboratory', 'meeting_type' => 'laboratory'],
            ],
        ];

        $result = $groups->invoke($solver, [$tba, $physical], true, [0, 1, 2], 1);

        self::assertNotEmpty($result);
        self::assertFalse((bool) ($result[0][0]['_room_tba'] ?? false));
        self::assertTrue((bool) ($result[array_key_last($result)][0]['_room_tba'] ?? false));
    }

    public function test_split_day_pairs_never_use_same_day_and_saturday_is_normal_physical_tier(): void
    {
        $solver = new CspSolver;
        $pairs = new ReflectionMethod($solver, 'splitLectureLabDayPairs');
        $course = new \App\Models\Course([
            'course_category' => 'major',
            'lecture_hours' => 1,
            'lab_hours' => 1,
        ]);

        foreach ($pairs->invoke($solver, $course, true, false) as $pair) {
            self::assertNotSame($pair[0], $pair[1]);
        }

        $tier = new ReflectionMethod($solver, 'candidateSearchDayTier');
        self::assertSame(0, $tier->invoke($solver, [
            'blocks' => [['day' => 'Saturday', 'mode' => 'on-site']],
        ]));
    }

    public function test_saturday_physical_candidates_precede_online_and_room_tba_fallbacks(): void
    {
        $solver = new CspSolver;
        $groups = new ReflectionMethod($solver, 'candidateGroupsByDayPriority');

        $physicalSaturday = [
            'mode' => 'on-site',
            'room_id' => 11,
            'room_type' => 'lecture',
            'blocks' => [['day' => 'Saturday', 'mode' => 'on-site', 'room_id' => 11]],
        ];
        $online = [
            'mode' => 'online',
            'room_type' => 'online',
            'blocks' => [['day' => 'Saturday', 'mode' => 'online']],
        ];
        $tba = [
            'mode' => 'on-site',
            'room_type' => 'lecture',
            '_room_tba' => true,
            'blocks' => [['day' => 'Saturday', 'mode' => 'on-site', '_room_tba' => true]],
        ];

        $result = $groups->invoke($solver, [$physicalSaturday, $online, $tba], true, [0, 1, 2], 1);

        self::assertNotEmpty($result);
        self::assertSame($physicalSaturday, $result[0][0]);
        self::assertSame($online, $result[1][0]);
        self::assertSame($tba, $result[2][0]);
    }

    public function test_four_non_overlapping_courses_can_share_a_day_when_resources_allow(): void
    {
        $solver = new CspSolver;
        $conflicts = new ReflectionMethod($solver, 'conflictsWithTentativeAssignments');

        $assignments = [];
        foreach ([0, 3, 6] as $index => $start) {
            $assignments[] = [
                'course_id' => $index + 1,
                'blocks' => [[
                    'day' => 'Monday',
                    'start_slot' => $start,
                    'end_slot' => $start + 3,
                    'mode' => 'on-site',
                ]],
            ];
        }

        $candidate = [
            'course_id' => 4,
            'blocks' => [[
                'day' => 'Monday',
                'start_slot' => 9,
                'end_slot' => 12,
                'mode' => 'on-site',
            ]],
        ];

        self::assertFalse($conflicts->invoke($solver, $candidate, $assignments, null, null));
    }

    private function onlineCandidate(int $startSlot, int $endSlot): array
    {
        return [
            'course_id' => 3,
            'department_id' => 7,
            'mode' => 'online',
            'room_type' => 'online',
            'blocks' => [[
                'day' => 'Monday',
                'start_slot' => $startSlot,
                'end_slot' => $endSlot,
                'mode' => 'online',
                'room_type' => 'online',
            ]],
        ];
    }

    private function onlineAssignment(int $courseId, int $startSlot, int $endSlot): array
    {
        return [
            'course_id' => $courseId,
            'department_id' => 7,
            'mode' => 'online',
            'room_type' => 'online',
            'blocks' => [[
                'day' => 'Monday',
                'start_slot' => $startSlot,
                'end_slot' => $endSlot,
                'mode' => 'online',
                'room_type' => 'online',
            ]],
        ];
    }
}
