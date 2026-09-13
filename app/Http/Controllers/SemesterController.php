<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\ScheduleSemesterArchiver;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SemesterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $semesters = Cache::remember(ApiCache::key('semesters.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Semester::orderBy('academic_year', 'desc')
            ->orderBy('semester', 'desc')
            ->get());

        return response()->json($semesters);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'semester' => SchedulingPolicy::allowedSemestersRule('required'),
            'academic_year' => 'nullable|string|max:50',
        ]);

        $academicYear = $request->academic_year;
        if (! $academicYear) {
            $currentYear = now()->month >= 6 ? now()->year : now()->year - 1;
            $nextYear = $currentYear + 1;
            $academicYear = $currentYear.'-'.$nextYear;
        }

        // Check for duplicates
        $exists = Semester::where('academic_year', $academicYear)
            ->where('semester', $request->semester)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This academic semester already exists.',
            ], 422);
        }

        $semester = Semester::create([
            'academic_year' => $academicYear,
            'semester' => $request->semester,
            'is_active' => false,
        ]);
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Semester created successfully.',
            'semester' => $semester,
        ], 201);
    }

    /**
     * Update an existing semester. Only the academic year and the enabled flag are
     * editable -- changing the semester would collide with the sibling rows.
     */
    public function update(Request $request, $id)
    {
        $semester = Semester::findOrFail($id);

        $validated = $request->validate([
            'academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('academic_year', $validated)) {
            [$start, $end] = array_map('intval', explode('-', $validated['academic_year']));

            if ($end !== $start + 1) {
                return response()->json([
                    'message' => 'The academic year must span two consecutive years, e.g. 2026-2027.',
                ], 422);
            }

            $duplicate = Semester::where('academic_year', $validated['academic_year'])
                ->where('semester', $semester->semester)
                ->where('id', '!=', $semester->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'Another semester already covers this academic year and semester.',
                ], 422);
            }

            $semester->academic_year = $validated['academic_year'];
        }

        if (array_key_exists('is_enabled', $validated)) {
            $semester->is_enabled = (bool) $validated['is_enabled'];
        }

        $semester->save();
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Semester updated successfully.',
            'semester' => $semester,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $semester = Semester::findOrFail($id);

        return response()->json($semester);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $semester = Semester::findOrFail($id);

        if ($semester->is_active) {
            return response()->json([
                'message' => 'Cannot archive the active academic semester. Please activate another semester first.',
            ], 400);
        }

        $semester->delete();
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Semester archived successfully.',
        ]);
    }

    /**
     * Activate the specified semester.
     */
    public function activate($id, ScheduleSemesterArchiver $archiver)
    {
        $actor = request()->user();
        $resetSectionCount = 0;
        $versions = DB::transaction(function () use ($id, $actor, $archiver, &$semester, &$resetSectionCount) {
            $semester = Semester::query()->lockForUpdate()->findOrFail($id);
            $previous = Semester::query()->where('is_active', true)->where('id', '!=', $semester->id)->lockForUpdate()->first();
            $versions = collect();

            if ($previous) {
                $schedules = Schedule::query()
                    ->where('semester_id', $previous->id)
                    ->whereIn('status', ScheduleSemesterArchiver::VPAA_APPROVED_STATUSES)
                    ->with(['section:id,section_name,year_level,semester,department_id,semester_id', 'course:id,course_code,course_name,course_category,units,lecture_hours,lab_hours', 'faculty:id,first_name,last_name', 'room:id,room_code', 'department:id,department_name,department_code,logo', 'split'])
                    ->get();
                $versions = $archiver->archive($schedules, (int) $actor->id, (int) $previous->id);

                Schedule::query()->where('semester_id', $previous->id)->update(['deleted_at' => now()]);
                $previous->is_active = false;
                $previous->save();

                // Semester rows are reused as semesters and academic years change.
                // Remove both operational section cycles so changing semesters
                // starts the newly active semester as a clean workspace and the
                // ended semester cannot reappear with stale sections later.
                Schedule::query()->where('semester_id', $semester->id)->update(['deleted_at' => now()]);
                $resetSectionCount = Sections::query()
                    ->whereIn('semester_id', [$previous->id, $semester->id])
                    ->delete();
            }

            $semester->is_active = true;
            $semester->save();

            SchedulingAuditLog::create([
                'user_id' => $actor->id,
                'semester_id' => $previous?->id,
                'department_id' => null,
                'action' => $versions->isNotEmpty() ? 'schedule_semester_archived' : 'semester_activated',
                'history_version_id' => $versions->first()?->id,
                'metadata' => [
                    'activated_semester_id' => $semester->id,
                    'history_version_ids' => $versions->pluck('id')->values()->all(),
                    'reset_section_count' => $resetSectionCount,
                ],
                'created_at' => now(),
            ]);

            return $versions;
        });
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'sections.by_department',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => $versions->isNotEmpty() ? 'Semester activated and previous schedules archived successfully.' : 'Semester activated successfully.',
            'semester' => $semester,
            'history_version_ids' => $versions->pluck('id')->values()->all(),
            'reset_section_count' => $resetSectionCount,
        ]);
    }

    /**
     * Get the active semester.
     */
    public function active()
    {
        $semester = Cache::remember(ApiCache::key('semesters.active'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Semester::where('is_active', true)->first());

        if (! $semester) {
            return response()->json(['message' => 'No active academic semester found.'], 404);
        }

        return response()->json($semester);
    }

    /**
     * Return the durable semester activation history used by the VPAA settings table.
     */
    public function activationHistory()
    {
        $logs = SchedulingAuditLog::query()
            ->whereIn('action', ['semester_activated', 'schedule_semester_archived'])
            ->whereNotNull('metadata')
            ->latest('created_at')->latest('id')
            ->get(['id', 'metadata', 'created_at']);

        $semesterIds = $logs
            ->map(fn (SchedulingAuditLog $log) => data_get($log->metadata, 'activated_semester_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $semesters = Semester::withTrashed()->whereIn('id', $semesterIds)->get()->keyBy('id');

        return response()->json($logs->map(function (SchedulingAuditLog $log) use ($semesters) {
            $semesterId = (int) data_get($log->metadata, 'activated_semester_id');
            $semester = $semesters->get($semesterId);

            return [
                'id' => $log->id,
                'semester_id' => $semesterId,
                'semester' => $semester?->semester,
                'academic_year' => $semester?->academic_year,
                'is_active' => (bool) $semester?->is_active,
                'activated_at' => $log->created_at?->toISOString(),
            ];
        })->filter(fn (array $entry) => $entry['semester_id'] > 0 && $entry['semester'] !== null)->values());
    }
}
