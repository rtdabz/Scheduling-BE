<?php

namespace Tests\Unit;

use App\Models\Departments;
use App\Services\Scheduling\Support\ProgramRoomShares;
use PHPUnit\Framework\TestCase;

/**
 * The division itself: equal, by whole days, the same every time, and
 * independent of which program generates first.
 */
class ProgramRoomSharesTest extends TestCase
{
    private const X = 1;

    private const Y = 2;

    public function test_all_rooms_shared_divides_every_room_equally_rotating_per_room(): void
    {
        $owners = ProgramRoomShares::ownersByDay(Departments::ROOM_SHARING_OPEN, $this->rooms([null, self::Y, null]), [self::X, self::Y]);

        // Home programs are ignored when every room is shared.
        $this->assertSame([self::X, self::Y, self::X, self::Y, self::X, self::Y], array_values($owners[1]));
        $this->assertSame([self::Y, self::X, self::Y, self::X, self::Y, self::X], array_values($owners[2]));
        $this->assertSame([self::X, self::Y, self::X, self::Y, self::X, self::Y], array_values($owners[3]));
        $this->assertSame(ProgramRoomShares::DIVIDED_DAYS, array_keys($owners[1]));

        // Every room is split 3/3, and each program has a room every day.
        foreach ($owners as $days) {
            $this->assertSame([self::X => 3, self::Y => 3], $this->tally($days));
        }
        foreach (ProgramRoomShares::DIVIDED_DAYS as $day) {
            $this->assertContains(self::X, array_column($owners, $day));
            $this->assertContains(self::Y, array_column($owners, $day));
        }
    }

    public function test_home_room_first_keeps_home_rooms_whole_and_divides_the_shared_room(): void
    {
        foreach ([Departments::ROOM_SHARING_HOME_FIRST, Departments::ROOM_SHARING_STRICT] as $policy) {
            $owners = ProgramRoomShares::ownersByDay($policy, $this->rooms([self::X, self::Y, null]), [self::X, self::Y]);

            $this->assertSame([self::X => 6], $this->tally($owners[1]), $policy);
            $this->assertSame([self::Y => 6], $this->tally($owners[2]), $policy);
            $this->assertSame([self::X => 3, self::Y => 3], $this->tally($owners[3]), $policy);
        }
    }

    public function test_three_programs_get_two_days_each(): void
    {
        $owners = ProgramRoomShares::ownersByDay(Departments::ROOM_SHARING_OPEN, $this->rooms([null]), [1, 2, 3]);

        $this->assertSame([1 => 2, 2 => 2, 3 => 2], $this->tally($owners[1]));
    }

    public function test_a_department_with_one_program_is_not_divided(): void
    {
        $this->assertSame([], ProgramRoomShares::ownersByDay(Departments::ROOM_SHARING_OPEN, $this->rooms([null, null]), [self::X]));
    }

    public function test_a_home_program_that_is_no_longer_a_program_of_the_department_leaves_the_room_divided(): void
    {
        $owners = ProgramRoomShares::ownersByDay(Departments::ROOM_SHARING_HOME_FIRST, $this->rooms([99]), [self::X, self::Y]);

        $this->assertSame([self::X => 3, self::Y => 3], $this->tally($owners[1]));
    }

    public function test_refusal_allows_the_owner_a_done_owners_days_and_sunday(): void
    {
        $shares = ['Monday' => ['program_id' => self::X, 'program_code' => 'X', 'lendable' => true, 'borrowable' => false]];

        $this->assertNull(ProgramRoomShares::refusal($shares, self::X, 'Monday', 'R1'));
        $this->assertNull(ProgramRoomShares::refusal($shares, self::Y, 'Sunday', 'R1'));
        $this->assertNull(ProgramRoomShares::refusal($shares, null, 'Monday', 'R1'));
        $this->assertStringContainsString("Room R1 is X's on Monday", (string) ProgramRoomShares::refusal($shares, self::Y, 'Monday', 'R1'));

        $shares['Monday']['borrowable'] = true;
        $this->assertNull(ProgramRoomShares::refusal($shares, self::Y, 'Monday', 'R1'));
    }

    /**
     * @param  list<int|null>  $homes
     * @return list<array{id: int, home_program_id: int|null}>
     */
    private function rooms(array $homes): array
    {
        return array_map(
            static fn (int $index, ?int $home): array => ['id' => $index + 1, 'home_program_id' => $home],
            array_keys($homes),
            $homes,
        );
    }

    /**
     * @param  array<string, int>  $days
     * @return array<int, int>
     */
    private function tally(array $days): array
    {
        $counts = array_count_values($days);
        ksort($counts);

        return $counts;
    }
}
