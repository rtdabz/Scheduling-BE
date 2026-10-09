<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TeachingHistoryArchive
{
    public const ARCHIVE_ACTION = 'schedule_semester_archived';

    /**
     * @param  list<int>  $facultyIds
     * @param  list<int>  $exceptSemesterIds
     * @return Collection<int, object{semester_id: int, academic_year: string|null, semester: string|null, is_active: bool, faculty_id: int, course_id: int, section_id: int, course_code: string|null, course_name: string|null, units: int, section_name: string|null}>
     */
    public function rows(array $facultyIds, ?int $courseId = null, array $exceptSemesterIds = []): Collection
    {
        if ($facultyIds === []) {
            return collect();
        }

        $items = DB::table('schedule_history_items')
            ->join('schedule_history_versions', 'schedule_history_versions.id', '=', 'schedule_history_items.history_version_id')
            ->join('semesters', 'semesters.id', '=', 'schedule_history_versions.semester_id')
            ->where('schedule_history_versions.action', self::ARCHIVE_ACTION)
            ->whereNull('semesters.deleted_at')
            ->when($exceptSemesterIds !== [], fn ($query) => $query->whereNotIn('semesters.id', $exceptSemesterIds))
            ->where(fn ($query) => $query
                ->whereIn('schedule_history_items.after_snapshot->faculty_id', $facultyIds)
                ->orWhereIn('schedule_history_items.after_snapshot->faculty_id', array_map('strval', $facultyIds)))
            ->when($courseId !== null, fn ($query) => $query->where(fn ($course) => $course
                ->where('schedule_history_items.after_snapshot->course_id', $courseId)
                ->orWhere('schedule_history_items.after_snapshot->course_id', (string) $courseId)))
            ->get([
                'schedule_history_versions.id as version_id',
                'schedule_history_versions.department_id',
                'semesters.id as semester_id',
                'semesters.academic_year',
                'semesters.semester',
                'semesters.is_active',
                'schedule_history_items.after_snapshot',
                'schedule_history_items.snapshot_metadata',
            ]);

        $latestVersionByScope = $items
            ->groupBy(static fn ($item): string => $item->semester_id.'|'.$item->department_id)
            ->map(static fn ($scope): int => (int) $scope->max('version_id'));

        return $items
            ->filter(static fn ($item): bool => (int) $item->version_id === $latestVersionByScope->get($item->semester_id.'|'.$item->department_id))
            ->map(static function ($item): object {
                $snapshot = json_decode((string) $item->after_snapshot, true) ?: [];
                $metadata = json_decode((string) $item->snapshot_metadata, true) ?: [];

                return (object) [
                    'semester_id' => (int) $item->semester_id,
                    'academic_year' => $item->academic_year,
                    'semester' => $item->semester,
                    'is_active' => (bool) $item->is_active,
                    'faculty_id' => (int) ($snapshot['faculty_id'] ?? 0),
                    'course_id' => (int) ($snapshot['course_id'] ?? 0),
                    'section_id' => (int) ($snapshot['section_id'] ?? 0),
                    'course_code' => $metadata['course_code'] ?? null,
                    'course_name' => $metadata['course_name'] ?? null,
                    'units' => (int) ($metadata['units'] ?? 0),
                    'section_name' => $metadata['section_name'] ?? null,
                ];
            })
            ->unique(static fn (object $row): string => "{$row->semester_id}|{$row->faculty_id}|{$row->course_id}|{$row->section_id}")
            ->values();
    }
}
