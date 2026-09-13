<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sections extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'section_name',
        'year_level',
        'semester',
        'department_id',
        'program_id',
        // The curriculum this cohort follows. A department mid-transition runs
        // several at once, so this cannot be inferred from the department alone.
        'curriculum_id',
        'semester_id',
        'status',
    ];
    protected $table = 'sections';

    /**
     * The form a section name is stored and compared in: surrounding space
     * trimmed, inner runs collapsed, upper-cased. "bsit  1a" and "BSIT 1A" are
     * the same cohort, so they must not be able to exist side by side.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Whether a live section already uses this name in the department for the
     * semester. Archived (soft-deleted) sections do not count, so a name frees up
     * once its section is archived; restoring that section checks again.
     */
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

            // Fill in the curriculum only when there is nothing to choose
            // between. One selectable curriculum is not a guess — it is the only
            // answer, and requiring the caller to restate it would break every
            // department that is not mid-transition.
            //
            // Two or more and the column stays null on purpose: picking one
            // would silently schedule a cohort against the wrong course list,
            // which is the exact failure this column exists to prevent. The
            // generator refuses such a section by name until somebody chooses.
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
}
