<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sections extends Model
{
    protected $fillable = [
        'section_name',
        'year_level',
        'semester',
        'department_id',
        'program_id',
        'curriculum_id',
        'semester_id',
        'status',
    ];
    protected $table = 'sections';

    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    public static function nameTaken(int $departmentId, int $semesterId, string $name, ?int $ignoreId = null): bool
    {
        return static::query()
            ->where('department_id', $departmentId)
            ->where('semester_id', $semesterId)
            ->whereRaw('UPPER(TRIM(section_name)) = ?', [static::normalizeName($name)])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $section): void {
            if ($section->isDirty('section_name') && $section->section_name !== null) {
                $section->section_name = static::normalizeName((string) $section->section_name);
            }
        });

        static::creating(function (self $section): void {
            if ($section->curriculum_id !== null || $section->department_id === null) {
                return;
            }

            $selectable = Curriculum::query()
                ->selectableFor((int) $section->department_id, $section->program_id === null ? null : (int) $section->program_id)
                ->pluck('id');

            if ($selectable->count() === 1) {
                $section->curriculum_id = (int) $selectable->first();
            }
        });
    }

    public function department()
    {
        return $this->belongsTo(Departments::class, 'department_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function curriculum()
    {
        return $this->belongsTo(Curriculum::class, 'curriculum_id');
    }

    public function academicSemester()
    {
        return $this->belongsTo(Semester::class, 'semester_id');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'section_id');
    }

    public function hasLockedSchedules(): bool
    {
        return $this->schedules()->whereNotIn('status', Schedule::UNLOCKED_STATUSES)->exists();
    }
}
