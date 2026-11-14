<?php

namespace App\Services;

use App\Models\ScheduleHistoryItem;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use Illuminate\Support\Collection;

class ScheduleSemesterArchiver
{
    public const VPAA_APPROVED_STATUSES = ['approved', 'faculty_assignment', 'reassignment', 'finalized'];

    public function archive(Collection $schedules, int $actorUserId, int $semesterId): Collection
    {
        if ($schedules->isEmpty()) {
            return collect();
        }

        $semester = $schedules->first()->academicSemester;
        $versions = collect();
        $signatoriesBySection = ScheduleSubmission::query()
            ->join('schedule_submission_sections as membership', 'membership.schedule_submission_id', '=', 'schedule_submissions.id')
            ->where('schedule_submissions.semester_id', $semesterId)
            ->whereIn('schedule_submissions.status', ['approved', 'partially_withdrawn'])
            ->whereIn('membership.section_id', $schedules->pluck('section_id')->filter()->unique())
            ->where('membership.state', 'included')
            ->orderByDesc('schedule_submissions.revision_number')
            ->orderByDesc('schedule_submissions.id')
            ->get(['schedule_submissions.signatories', 'membership.section_id'])
            ->unique('section_id')
            ->pluck('signatories', 'section_id');

        foreach ($schedules->groupBy('department_id') as $departmentSchedules) {
            $version = ScheduleHistoryVersion::create([
                'semester_id' => $semesterId,
                'academic_year' => $semester?->academic_year,
                'semester' => $semester?->semester,
                'department_id' => $departmentSchedules->first()->department_id,
                'actor_user_id' => $actorUserId,
                'action' => 'schedule_semester_archived',
                'source' => 'semester_change',
                'change_summary' => [
                    'history_scope' => 'entire_department_schedule',
                    'schedule_count' => $departmentSchedules->count(),
                    'section_count' => $departmentSchedules->pluck('section_id')->filter()->unique()->count(),
                ],
            ]);
            $versions->push($version);

            foreach ($departmentSchedules as $schedule) {
                $snapshot = $schedule->getAttributes();
                $snapshot['split_group_id'] = $schedule->split_group_id;
                $snapshot['meeting_type'] = $schedule->meeting_type;
                $snapshot['meeting_index'] = $schedule->meeting_index;
                // Keep each section's recorded officials; legacy records must not borrow today's officials.
                $snapshot['signatories'] = $signatoriesBySection->get($schedule->section_id);

                ScheduleHistoryItem::create([
                    'history_version_id' => $version->id,
                    'original_schedule_id' => $schedule->id,
                    'after_snapshot' => $snapshot,
                    'snapshot_metadata' => [
                        'event' => 'semester_change',
                        'section_name' => $schedule->section?->section_name,
                        'section_year_level' => $schedule->section?->year_level,
                        'section_semester' => $schedule->section?->semester,
                        'course_code' => $schedule->course?->course_code,
                        'course_name' => $schedule->course?->course_name,
                        'course_category' => $schedule->course?->course_category,
                        'units' => $schedule->course?->units,
                        'lecture_hours' => $schedule->course?->lecture_hours,
                        'lab_hours' => $schedule->course?->lab_hours,
                        'faculty_name' => $schedule->faculty ? trim($schedule->faculty->first_name.' '.$schedule->faculty->last_name) : null,
                        'room_name' => $schedule->room?->room_code,
                        'department_name' => $schedule->department?->department_name,
                        'department_code' => $schedule->department?->department_code,
                        'department_logo' => $schedule->department?->logo,
                    ],
                ]);
            }
        }

        return $versions;
    }
}
