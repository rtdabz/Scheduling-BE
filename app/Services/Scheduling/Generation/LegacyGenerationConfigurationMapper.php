<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Generation;

use App\Services\Scheduling\Domain\GenerationConfiguration;

final class LegacyGenerationConfigurationMapper
{
    /** @param array<string, mixed> $input */
    public function map(array $input): GenerationConfiguration
    {
        $anchoredByCourseId = [];
        foreach ($input['anchored_schedules'] ?? [] as $courseId => $schedule) {
            if (! is_array($schedule)) {
                continue;
            }

            if (array_is_list($schedule) && (int) $courseId > 0) {
                $anchoredByCourseId[(int) $courseId] = $schedule;

                continue;
            }

            $resolvedCourseId = (int) ($schedule['course_id'] ?? $courseId);
            if ($resolvedCourseId > 0) {
                $anchoredByCourseId[$resolvedCourseId][] = $schedule;
            }
        }

        return GenerationConfiguration::fromArray([
            ...$input,
            'delivery_mode' => $input['delivery_mode'] ?? $input['mode'] ?? 'on-site',
            'anchored_schedules' => $anchoredByCourseId,
        ]);
    }
}
