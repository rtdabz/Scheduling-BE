<?php

namespace App\Services\Curriculum;

use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;

/**
 * Assigns the unique internal `curriculum.code` for imports: PROGRAM[-MAJOR]-STARTYEAR, falling back to the
 * department code, with -2, -3, ... appended when taken. Imports never accept a client-supplied code.
 */
final class CurriculumCodeGenerator
{
    private const MAX_LENGTH = 255;

    public static function base(Departments $department, ?Program $program, string $effectiveSchoolYear): string
    {
        $parts = $program !== null
            ? [$program->code, $program->major]
            : [$department->department_code];
        $parts[] = preg_match('/^(\d{4})-\d{4}$/', $effectiveSchoolYear, $years) ? $years[1] : null;

        $segments = array_values(array_filter(array_map(
            static fn (?string $part): string => trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper((string) $part)), '-'),
            $parts,
        )));

        return mb_substr(implode('-', $segments) ?: 'CURRICULUM', 0, self::MAX_LENGTH - 8);
    }

    public static function next(string $base): string
    {
        $taken = Curriculum::query()
            ->where('code', $base)
            ->orWhere('code', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base).'-%')
            ->pluck('code')
            ->flip();

        if (! isset($taken[$base])) {
            return $base;
        }
        for ($suffix = 2; isset($taken[$base.'-'.$suffix]); $suffix++);

        return $base.'-'.$suffix;
    }
}
