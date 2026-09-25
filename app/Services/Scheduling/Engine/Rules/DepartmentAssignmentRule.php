<?php

namespace App\Services\Scheduling\Engine\Rules;

use App\Models\Program;
use App\Services\Scheduling\Support\ProgramRoomShares;
use App\Services\Scheduling\Support\RoomAccessPolicy;
use App\Services\Scheduling\Support\SchedulingPolicy;

/**
 * schedule_department_alignment, room_department_alignment,
 * major_department_alignment, major_faculty_department_alignment,
 * service_subject_faculty_department_alignment, major_faculty_program_alignment,
 * service_subject_faculty_program_alignment.
 *
 * Whether the schedule, its room, its course and its instructor all belong to
 * (or are granted to) the department and program doing the scheduling.
 */
final class DepartmentAssignmentRule
{
    public function __construct(private readonly RuleLookupCache $lookups) {}

    /**
     * @param  array<string, mixed>  $attempt
     * @return list<array<string, mixed>>
     */
    public function check(array $attempt, AttemptRecords $records): array
    {
        $violations = [];
        $section = $records->section;
        $course = $records->course;

        $attemptDepartmentId = $attempt['department_id'] ?? $section->department_id;
        if ((int) $attemptDepartmentId !== (int) $section->department_id) {
            $violations[] = [
                'rule' => 'schedule_department_alignment',
                'message' => 'Schedule department must match the selected section department.',
            ];
        }

        $roomViolation = $this->roomDepartment($attempt, $records) ?? $this->roomProgramShare($attempt, $records);
        if ($roomViolation !== null) {
            $violations[] = $roomViolation;
        }

        $courseCategory = $course->course_category ?? $course->subject_category ?? 'major';
        if (
            $courseCategory === 'major'
            && $course->department_id !== null
            && (int) $course->department_id !== (int) $section->department_id
        ) {
            $violations[] = [
                'rule' => 'major_department_alignment',
                'message' => 'Major course department does not match the selected section department.',
            ];
        }

        return [...$violations, ...$this->instructorDepartment($records)];
    }

    /**
     * Another department's room is reachable only through an approved room
     * request, and only inside its granted windows.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    private function roomDepartment(array $attempt, AttemptRecords $records): ?array
    {
        $room = $records->room;
        if ($room === null || $room->department_id === null || (int) $room->department_id === $records->departmentId()) {
            return null;
        }

        $grantWindows = $this->lookups->remember(
            'room-grants:'.$records->departmentId().':'.$records->semester->id,
            fn () => app(RoomAccessPolicy::class)->grantWindowsFor($records->departmentId(), (int) $records->semester->id),
        )[(int) $room->id] ?? null;

        if ($grantWindows === null) {
            return [
                'rule' => 'room_department_alignment',
                'message' => 'Selected room is not shared and does not belong to the selected section department.',
            ];
        }

        if (! RoomAccessPolicy::fitsWindows(
            $grantWindows,
            (string) ($attempt['day'] ?? ''),
            (string) ($attempt['start_time'] ?? '00:00'),
            (string) ($attempt['end_time'] ?? '00:00'),
        )) {
            return [
                'rule' => 'room_department_alignment',
                'message' => "Room {$room->room_code} is granted to your department only on "
                    .RoomAccessPolicy::describe($grantWindows).'.',
            ];
        }

        return null;
    }

    /**
     * In a department with several programs, each of its rooms belongs to one
     * program per weekday (ProgramRoomShares). A section may use another
     * program's day only once that program is done, and never under `strict`.
     *
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    private function roomProgramShare(array $attempt, AttemptRecords $records): ?array
    {
        $room = $records->room;
        $section = $records->section;
        if ($room === null || $section->program_id === null || (int) $room->department_id !== (int) $section->department_id) {
            return null;
        }

        $shares = $this->lookups->remember(
            'program-room-shares:'.(int) $section->department_id.':'.(int) $records->semester->id,
            fn () => app(ProgramRoomShares::class)->forDepartment((int) $section->department_id, (int) $records->semester->id),
        );

        $message = ProgramRoomShares::refusal(
            $shares[(int) $room->id] ?? null,
            (int) $section->program_id,
            (string) ($attempt['day'] ?? ''),
            (string) $room->room_code,
        );

        return $message === null ? null : ['rule' => 'room_department_alignment', 'message' => $message];
    }

    /**
     * A minor or service course with no assigned teaching department is open to
     * any department: shared minors such as PATH FIT are taught by instructors
     * from outside the section's department, which is what the external
     * instructor assignment path is for. Majors are restricted by their own
     * department and program.
     *
     * @return list<array<string, mixed>>
     */
    private function instructorDepartment(AttemptRecords $records): array
    {
        $faculty = $records->faculty;
        if ($faculty === null) {
            return [];
        }

        $violations = [];
        $course = $records->course;
        $isMajor = SchedulingPolicy::isMajorCourse($course);

        if ($isMajor) {
            // A major belongs to the department — and, when recorded, the
            // program — that offers it, so it is taught from inside that
            // program rather than delegated like a service course.
            $majorDepartmentId = SchedulingPolicy::majorTeachingDepartmentId($course, $records->departmentId());

            if ($majorDepartmentId !== null && (int) $faculty->department_id !== $majorDepartmentId) {
                $violations[] = [
                    'rule' => 'major_faculty_department_alignment',
                    'message' => 'A major course must be assigned to an instructor from the department that offers it.',
                ];
            }
        } else {
            $assignedTeachingDepartmentId = SchedulingPolicy::assignedTeachingDepartmentId($course);
            if ($assignedTeachingDepartmentId !== null && (int) $faculty->department_id !== $assignedTeachingDepartmentId) {
                $violations[] = [
                    'rule' => 'service_subject_faculty_department_alignment',
                    'message' => 'This service course must be assigned to an instructor from the college that offers it.',
                ];
            }
        }

        // One program check for every course; majors and service courses keep
        // their own rule IDs because the UI and reports key on them.
        $requiredProgramId = SchedulingPolicy::requiredTeachingProgramId($course);
        if ($requiredProgramId !== null && (int) $faculty->program_id !== $requiredProgramId) {
            $program = $this->lookups->remember('program:'.$requiredProgramId, fn () => Program::find($requiredProgramId));
            $programLabel = $program?->code ?? $program?->name;
            $subject = $isMajor ? 'This major course belongs to' : 'This course is assigned to';

            $violations[] = [
                'rule' => $isMajor ? 'major_faculty_program_alignment' : 'service_subject_faculty_program_alignment',
                'message' => $programLabel !== null
                    ? "{$subject} the {$programLabel} program, so the selected instructor must belong to that program."
                    : "{$subject} a program the selected instructor is not assigned to.",
            ];
        }

        return $violations;
    }
}
