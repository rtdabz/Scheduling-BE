<?php

namespace App\Services\Curriculum;

use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;

/**
 * Assigns the unique internal `curriculum.code` for imports: CMO-NUMBER-SERIES when the reference CMO is
 * given, otherwise PROGRAM[-MAJOR]-STARTYEAR falling back to the department code, with -2, -3, ... appended
 * when taken. Imports never accept a client-supplied code.
 */
final class CurriculumCodeGenerator
{
    private const MAX_LENGTH = 255;

    public static function base(Departments $department, ?Program $program, string $effectiveSchoolYear, ?string $cmoNumber = null, ?int $cmoSeries = null): string
    {
        $cmoNumber = self::normalizeCmoNumber($cmoNumber);
        if ($cmoNumber !== null && $cmoSeries !== null) {
            $parts = ['CMO', $cmoNumber, (string) $cmoSeries];
        } else {
            $parts = $program !== null
                ? [$program->code, $program->major]
                : [$department->department_code];
            $parts[] = preg_match('/^(\d{4})-\d{4}$/', $effectiveSchoolYear, $years) ? $years[1] : null;
        }

        return mb_substr(implode('-', self::segments($parts)) ?: 'CURRICULUM', 0, self::MAX_LENGTH - 8);
    }

    /** Uppercased, separators collapsed to single hyphens; blank becomes null. */
    public static function normalizeCmoNumber(?string $cmoNumber): ?string
    {
        return self::segments([$cmoNumber])[0] ?? null;
    }

    public static function next(string $base): string
    {
        // Every existing row counts, archived ones included, so a released code is never handed out again.
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

    /**
     * @param  list<string|null>  $parts
     * @return list<string>
     */
    private static function segments(array $parts): array
    {
        return array_values(array_filter(array_map(
            static fn (?string $part): string => trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper((string) $part)), '-'),
            $parts,
        )));
    }
}
