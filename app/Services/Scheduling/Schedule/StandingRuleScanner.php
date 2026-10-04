<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Schedule;

use App\Models\Schedule;
use App\Services\Scheduling\Engine\RuleEngine;

final class StandingRuleScanner
{
    private const PAIRWISE_RULES = ['section_conflict', 'room_conflict', 'faculty_conflict', 'subject_section_time_conflict'];


    /**
     * @return list<array<string, mixed>>
     */
    public function scan(int $semesterId, ?int $departmentId = null, ?int $sectionId = null): array
    {
        if ($semesterId <= 0) {
            return [];
        }

        $schedules = Schedule::query()
            ->with(['course:id,course_code,course_name', 'section:id,section_name', 'room:id,room_code', 'faculty:id,first_name,last_name'])
            ->where('semester_id', $semesterId)
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->when($sectionId !== null, fn ($query) => $query->where('section_id', $sectionId))
            ->orderBy('id')
            ->get();

        $ruleEngine = app(RuleEngine::class);

        $issues = [];
        foreach ($schedules as $schedule) {
            $attempt = [
                ...$schedule->attributesToArray(),
                'ignore_schedule_id' => (int) $schedule->id,
            ];

            $seen = [];
            foreach ($ruleEngine->validateStanding($attempt) as $violation) {
                $rule = (string) ($violation['rule'] ?? '');
                if ($rule === '' || in_array($rule, self::PAIRWISE_RULES, true) || isset($seen[$rule])) {
                    continue;
                }
                $seen[$rule] = true;

                $issues[] = [
                    'id' => "rule_issue:{$rule}:{$schedule->id}",
                    'rule' => $rule,
                    'message' => (string) ($violation['message'] ?? ''),
                    'schedule' => $this->row($schedule),
                ];
            }
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function row(Schedule $schedule): array
    {
        $faculty = $schedule->faculty;

        return [
            'id' => (int) $schedule->id,
            'semester_id' => (int) $schedule->semester_id,
            'section_id' => (int) $schedule->section_id,
            'course_id' => (int) $schedule->course_id,
            'department_id' => (int) $schedule->department_id,
            'day' => (string) $schedule->day,
            'start_time' => (string) $schedule->start_time,
            'end_time' => (string) $schedule->end_time,
            'mode' => (string) $schedule->mode,
            'status' => (string) $schedule->status,
            'room_id' => $schedule->room_id !== null ? (int) $schedule->room_id : null,
            'faculty_id' => $schedule->faculty_id !== null ? (int) $schedule->faculty_id : null,
            'course_code' => $schedule->course?->course_code,
            'course_name' => $schedule->course?->course_name,
            'section_name' => $schedule->section?->section_name,
            'room_code' => $schedule->room?->room_code,
            'faculty_name' => $faculty === null
                ? null
                : (trim(implode(' ', array_filter([$faculty->first_name, $faculty->last_name]))) ?: null),
        ];
    }
}
