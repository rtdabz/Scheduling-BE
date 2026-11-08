<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Rooms;
use App\Models\Sections;
use App\Services\Scheduling\Support\ConflictMessage;

/**
 * Words clashes between two rows of the same save, in the shared conflict format.
 */
final class BatchConflictMessages
{
    /**
     * @param  list<BatchConflict>  $conflicts
     * @param  array<int|string, array<string, mixed>>  $operations  the rows the conflicts index into
     * @return list<array<string, mixed>>
     */
    public function describe(array $conflicts, array $operations): array
    {
        if ($conflicts === []) {
            return [];
        }

        $names = $this->names($operations);

        return array_map(function (BatchConflict $conflict) use ($operations, $names): array {
            $row = $operations[$conflict->index] ?? [];
            $other = $operations[$conflict->otherIndex] ?? [];

            return [
                'rule' => $conflict->rule,
                'operation_index' => $conflict->index,
                'course_code' => $conflict->courseCode ?? 'Course',
                'day' => $conflict->day,
                'message' => $this->message($conflict, $row, $other, $names),
            ];
        }, $conflicts);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $other
     * @param  array<string, array<int, string>>  $names
     */
    private function message(BatchConflict $conflict, array $row, array $other, array $names): string
    {
        $day = (string) ($row['day'] ?? $conflict->day ?? '');
        $start = (string) ($row['start_time'] ?? $conflict->overlapStart ?? '');
        $end = (string) ($row['end_time'] ?? $conflict->overlapEnd ?? '');
        $booking = [
            'department' => $this->departmentOf($other, $names),
            'course' => $conflict->otherCourseCode,
            'section' => $names['sections'][(int) ($other['section_id'] ?? 0)] ?? null,
            'start' => (string) ($other['start_time'] ?? $conflict->overlapStart ?? ''),
            'end' => (string) ($other['end_time'] ?? $conflict->overlapEnd ?? ''),
            'pending' => true,
        ];

        return match ($conflict->rule) {
            BatchConflict::RULE_ROOM => ConflictMessage::room(
                $names['rooms'][(int) ($row['room_id'] ?? 0)] ?? null, $day, $start, $end, $booking,
            ),
            BatchConflict::RULE_FACULTY => ConflictMessage::instructor(
                $names['faculties'][(int) ($row['faculty_id'] ?? 0)] ?? null, $day, $start, $end, $booking,
            ),
            BatchConflict::RULE_SECTION => ConflictMessage::section(
                $names['sections'][(int) ($row['section_id'] ?? 0)] ?? null, $day, $start, $end, $booking,
            ),
            BatchConflict::RULE_SUBJECT_SECTION_TIME => ConflictMessage::onlineCourse(
                $conflict->courseCode, $day, $start, $end, $booking,
            ),
            default => 'Two classes in this save overlap on '.ConflictMessage::slot($day, $start, $end).'. Please choose another time.',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<int, string>>  $names
     */
    private function departmentOf(array $row, array $names): ?string
    {
        $departmentId = (int) ($row['department_id'] ?? 0)
            ?: (int) ($names['section_departments'][(int) ($row['section_id'] ?? 0)] ?? 0);

        return $names['departments'][$departmentId] ?? null;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $operations
     * @return array<string, array<int, string>>
     */
    private function names(array $operations): array
    {
        $ids = static fn (string $key): array => array_values(array_unique(array_filter(array_map(
            static fn (array $op): int => (int) ($op[$key] ?? 0),
            $operations,
        ))));

        $sections = Sections::query()->whereIn('id', $ids('section_id'))->get(['id', 'section_name', 'department_id']);
        $departmentIds = array_values(array_unique(array_filter([
            ...$ids('department_id'),
            ...$sections->pluck('department_id')->map(static fn ($id): int => (int) $id)->all(),
        ])));

        return [
            'rooms' => $this->pluck(Rooms::query()->whereIn('id', $ids('room_id'))->pluck('room_code', 'id')->all()),
            'sections' => $this->pluck($sections->pluck('section_name', 'id')->all()),
            'section_departments' => $this->pluck($sections->pluck('department_id', 'id')->all()),
            'departments' => $this->pluck(Departments::query()->whereIn('id', $departmentIds)->pluck('department_code', 'id')->all()),
            'faculties' => Faculty::query()->whereIn('id', $ids('faculty_id'))->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(static fn (Faculty $f): array => [(int) $f->id => trim("{$f->first_name} {$f->last_name}")])
                ->all(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    private function pluck(array $values): array
    {
        $out = [];
        foreach ($values as $id => $value) {
            $out[(int) $id] = (string) $value;
        }

        return $out;
    }
}
