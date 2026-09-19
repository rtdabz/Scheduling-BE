<?php

namespace Tests\Feature;

use App\Services\Scheduling\YearLevel\SessionRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session recommendations are counted from free start times, not written as
 * fixed advice: a session is offered only when every meeting still fits there.
 * The default grid opens at 7:00, so morning is 7:00-11:30, afternoon
 * 11:30-16:00 and evening 16:00-20:30.
 */
class SessionRecommenderTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function test_a_full_morning_is_not_offered_and_the_freest_session_comes_first(): void
    {
        $rows = [
            ...$this->busyRows(sectionId: 1, roomId: null, start: '07:00', end: '11:30'),
            // Tuesday evening is taken too, so evening has fewer starts than afternoon.
            ['section_id' => 1, 'room_id' => null, 'day' => 'Tuesday', 'start_time' => '16:00', 'end_time' => '20:30', 'mode' => 'online'],
        ];
        $recommender = new SessionRecommender([], $rows);

        $options = $recommender->courseOptions(1, [$this->meeting(3, ['online'])], false, null);

        $this->assertSame(['afternoon', 'evening'], array_column($options, 'period'));
        $this->assertGreaterThan($options[1]['free_starts'], $options[0]['free_starts']);
    }

    public function test_an_on_site_meeting_needs_a_free_room_of_its_type(): void
    {
        $rooms = [5 => ['id' => 5, 'room_type' => 'lecture', 'status' => 'available']];
        // Another section holds the only lecture room every afternoon.
        $recommender = new SessionRecommender($rooms, $this->busyRows(sectionId: 2, roomId: 5, start: '11:30', end: '16:00'));

        $onSite = array_column($recommender->courseOptions(1, [$this->meeting(3, ['lecture'])], false, 'morning'), 'period');
        $online = array_column($recommender->courseOptions(1, [$this->meeting(3, ['online'])], false, 'morning'), 'period');

        $this->assertSame(['evening'], $onSite);
        $this->assertContains('afternoon', $online, 'An online meeting needs no room.');
    }

    public function test_a_field_course_is_not_offered_the_evening_past_the_field_end_time(): void
    {
        $recommender = new SessionRecommender([], []);

        $options = $recommender->courseOptions(1, [$this->meeting(4, ['field'])], true, null);

        $this->assertNotContains('evening', array_column($options, 'period'));
        $this->assertContains('morning', array_column($options, 'period'));
    }

    public function test_the_course_being_moved_does_not_block_its_own_new_session(): void
    {
        $ownRows = $this->busyRows(sectionId: 1, roomId: null, start: '11:30', end: '16:00');
        $recommender = new SessionRecommender([], $ownRows);

        $options = $recommender->courseOptions(1, [$this->meeting(3, ['online'])], false, 'evening', $ownRows);

        $this->assertContains('afternoon', array_column($options, 'period'));
    }

    /** Seven three-hour meetings need seven days at one per window; Mon-Sat is six. */
    public function test_a_section_is_only_offered_a_session_its_meetings_pack_into(): void
    {
        $recommender = new SessionRecommender([], []);
        $meetings = array_fill(0, 7, $this->meeting(6, ['online']) + ['field' => false]);

        $this->assertSame([], $recommender->sectionOptions(1, $meetings, 'morning'));
        $this->assertSame(
            ['afternoon', 'evening'],
            array_column($recommender->sectionOptions(1, array_slice($meetings, 0, 6), 'morning'), 'period'),
        );
    }

    /** @return array{duration_slots: int, room_types: list<string>} */
    private function meeting(int $slots, array $roomTypes): array
    {
        return ['duration_slots' => $slots, 'room_types' => $roomTypes];
    }

    /** @return list<array<string, mixed>> */
    private function busyRows(int $sectionId, ?int $roomId, string $start, string $end): array
    {
        return array_map(static fn (string $day): array => [
            'section_id' => $sectionId,
            'room_id' => $roomId,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'mode' => $roomId === null ? 'online' : 'on-site',
        ], self::WEEK);
    }
}
