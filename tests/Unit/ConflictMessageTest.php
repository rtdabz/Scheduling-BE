<?php

namespace Tests\Unit;

use App\Services\Scheduling\Support\ConflictMessage;
use PHPUnit\Framework\TestCase;

class ConflictMessageTest extends TestCase
{
    private const BOOKING = [
        'department' => 'CIT',
        'course' => 'PATH FIT 2',
        'section' => 'BSIT 1I',
        'start' => '07:00:00',
        'end' => '09:00:00',
    ];

    public function test_room_message_matches_the_standard_wording(): void
    {
        $this->assertSame(
            'Room BA 201 is unavailable on Mon 7:00 AM–10:00 AM. It is booked by CIT for PATH FIT 2 (BSIT 1I) from 7:00 AM–9:00 AM. Please choose another time or room.',
            ConflictMessage::room('BA 201', 'Monday', '07:00', '10:00', self::BOOKING),
        );
    }

    public function test_missing_details_degrade_to_plain_wording(): void
    {
        $this->assertSame(
            'The selected room is unavailable on Tue 8:00 AM–9:00 AM. It is booked for another class from 8:30 AM–9:30 AM. Please choose another time or room.',
            ConflictMessage::room(null, 'Tuesday', '8:00', '9:00', ['start' => '08:30', 'end' => '09:30']),
        );
    }

    public function test_further_clashes_are_counted(): void
    {
        $this->assertStringContainsString(
            'from 7:00 AM–9:00 AM, plus 2 more classes.',
            ConflictMessage::room('BA 201', 'Monday', '07:00', '10:00', self::BOOKING, 2),
        );
    }

    public function test_a_clash_inside_the_same_save_says_so(): void
    {
        $this->assertStringContainsString(
            'It is booked by CIT for PATH FIT 2 (BSIT 1I) from 7:00 AM–9:00 AM in this same save.',
            ConflictMessage::room('BA 201', 'Monday', '07:00', '10:00', [...self::BOOKING, 'pending' => true]),
        );
    }

    public function test_other_conflict_types_follow_the_same_structure(): void
    {
        $this->assertSame(
            'Instructor Ana Cruz is unavailable on Wed 1:00 PM–2:30 PM. They are already teaching PATH FIT 2 (BSIT 1I) for CIT from 7:00 AM–9:00 AM. Please choose another time or instructor.',
            ConflictMessage::instructor('Ana Cruz', 'Wednesday', '13:00', '14:30', self::BOOKING),
        );
        $this->assertSame(
            'Section BSIT 1I is unavailable on Mon 8:00 AM–9:00 AM. It already has PATH FIT 2 from 7:00 AM–9:00 AM. Please choose another time.',
            ConflictMessage::section('BSIT 1I', 'Monday', '08:00', '09:00', self::BOOKING),
        );
    }

    public function test_double_booking_names_both_classes(): void
    {
        $this->assertSame(
            'Room BA 201 is double-booked on Mon 8:00 AM–9:00 AM. CIT has PATH FIT 2 (BSIT 1I) from 7:00 AM–9:00 AM, and CBA has ACCT 1 (BSA 1A) from 8:00 AM–10:00 AM. Move one class to another time or room.',
            ConflictMessage::doubleBooked(
                'room_conflict',
                'Monday',
                '08:00',
                '09:00',
                [...self::BOOKING, 'room' => 'BA 201'],
                ['department' => 'CBA', 'course' => 'ACCT 1', 'section' => 'BSA 1A', 'start' => '08:00', 'end' => '10:00'],
            ),
        );
    }
}
