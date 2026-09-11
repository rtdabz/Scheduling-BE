<?php

namespace App\Services\Scheduling\Generation;

use App\Models\Sections;
use Illuminate\Support\Collection;

interface ScheduleRequirementBuilder
{
    /** @return array<int, list<array<string, mixed>>> */
    public function build(Sections $section, Collection $courses, array $options = []): array;
}
