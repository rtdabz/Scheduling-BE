<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Terms;
use App\Services\ScheduleTermArchiver;
use App\Services\Scheduling\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TermsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $terms = Cache::remember(ApiCache::key('terms.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Terms::orderBy('academic_year', 'desc')
            ->orderBy('semester', 'desc')
            ->get());

        return response()->json($terms);
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
        $exists = Terms::where('academic_year', $academicYear)
            ->where('semester', $request->semester)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This academic term already exists.',
            ], 422);
        }

        $term = Terms::create([
            'academic_year' => $academicYear,
            'semester' => $request->semester,
            'is_active' => false,
        ]);
        ApiCache::forgetGroups([
            'terms.index',
            'terms.active',
            'sections.index',
            'sections.by_term',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Term created successfully.',
            'term' => $term,
        ], 201);
    }

    /**
     * Update an existing term. Only the academic year and the enabled flag are
     * editable -- changing the semester would collide with the sibling rows.
     */
    public function update(Request $request, $id)
    {
        $term = Terms::findOrFail($id);

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

            $duplicate = Terms::where('academic_year', $validated['academic_year'])
                ->where('semester', $term->semester)
                ->where('id', '!=', $term->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'Another term already covers this academic year and semester.',
                ], 422);
            }

            $term->academic_year = $validated['academic_year'];
        }

        if (array_key_exists('is_enabled', $validated)) {
            $term->is_enabled = (bool) $validated['is_enabled'];
        }

        $term->save();
        ApiCache::forgetGroups([
            'terms.index',
            'terms.active',
            'sections.index',
            'sections.by_term',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Term updated successfully.',
            'term' => $term,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $term = Terms::findOrFail($id);

        return response()->json($term);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $term = Terms::findOrFail($id);

        if ($term->is_active) {
            return response()->json([
                'message' => 'Cannot archive the active academic term. Please activate another term first.',
            ], 400);
        }

        $term->delete();
        ApiCache::forgetGroups([
            'terms.index',
            'terms.active',
            'sections.index',
            'sections.by_term',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Term archived successfully.',
        ]);
    }

    /**
     * Activate the specified term.
     */
    public function activate($id, ScheduleTermArchiver $archiver)
    {
        $actor = request()->user();
        $resetSectionCount = 0;
        $versions = DB::transaction(function () use ($id, $actor, $archiver, &$term, &$resetSectionCount) {
            $term = Terms::query()->lockForUpdate()->findOrFail($id);
            $previous = Terms::query()->where('is_active', true)->where('id', '!=', $term->id)->lockForUpdate()->first();
            $versions = collect();

            if ($previous) {
                $schedules = Schedule::query()
                    ->where('term_id', $previous->id)
                    ->whereIn('status', ScheduleTermArchiver::VPAA_APPROVED_STATUSES)
                    ->with(['section:id,section_name,year_level,semester,department_id,term_id', 'course:id,course_code,course_name,course_category,units,lecture_hours,lab_hours', 'faculty:id,first_name,last_name', 'room:id,room_code', 'department:id,department_name,department_code,logo', 'split'])
                    ->get();
                $versions = $archiver->archive($schedules, (int) $actor->id, (int) $previous->id);

                Schedule::query()->where('term_id', $previous->id)->update(['deleted_at' => now()]);
                $previous->is_active = false;
                $previous->save();

                // Term rows are reused as semesters and academic years change.
                // Remove both operational section cycles so changing terms
                // starts the newly active term as a clean workspace and the
                // ended term cannot reappear with stale sections later.
                Schedule::query()->where('term_id', $term->id)->update(['deleted_at' => now()]);
                $resetSectionCount = Sections::query()
                    ->whereIn('term_id', [$previous->id, $term->id])
                    ->delete();
            }

            $term->is_active = true;
            $term->save();

            SchedulingAuditLog::create([
                'user_id' => $actor->id,
                'term_id' => $previous?->id,
                'department_id' => null,
                'action' => $versions->isNotEmpty() ? 'schedule_term_archived' : 'term_activated',
                'history_version_id' => $versions->first()?->id,
                'metadata' => [
                    'activated_term_id' => $term->id,
                    'history_version_ids' => $versions->pluck('id')->values()->all(),
                    'reset_section_count' => $resetSectionCount,
                ],
                'created_at' => now(),
            ]);

            return $versions;
        });
        ApiCache::forgetGroups([
            'terms.index',
            'terms.active',
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'departments.index',
            'initial.data',
        ]);

        return response()->json([
            'message' => $versions->isNotEmpty() ? 'Term activated and previous schedules archived successfully.' : 'Term activated successfully.',
            'term' => $term,
            'history_version_ids' => $versions->pluck('id')->values()->all(),
            'reset_section_count' => $resetSectionCount,
        ]);
    }

    /**
     * Get the active term.
     */
    public function active()
    {
        $term = Cache::remember(ApiCache::key('terms.active'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Terms::where('is_active', true)->first());

        if (! $term) {
            return response()->json(['message' => 'No active academic term found.'], 404);
        }

        return response()->json($term);
    }

    /**
     * Return the durable term activation history used by the VPAA settings table.
     */
    public function activationHistory()
    {
        $logs = SchedulingAuditLog::query()
            ->whereIn('action', ['term_activated', 'schedule_term_archived'])
            ->whereNotNull('metadata')
            ->latest('created_at')->latest('id')
            ->get(['id', 'metadata', 'created_at']);

        $termIds = $logs
            ->map(fn (SchedulingAuditLog $log) => data_get($log->metadata, 'activated_term_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $terms = Terms::withTrashed()->whereIn('id', $termIds)->get()->keyBy('id');

        return response()->json($logs->map(function (SchedulingAuditLog $log) use ($terms) {
            $termId = (int) data_get($log->metadata, 'activated_term_id');
            $term = $terms->get($termId);

            return [
                'id' => $log->id,
                'term_id' => $termId,
                'semester' => $term?->semester,
                'academic_year' => $term?->academic_year,
                'is_active' => (bool) $term?->is_active,
                'activated_at' => $log->created_at?->toISOString(),
            ];
        })->filter(fn (array $entry) => $entry['term_id'] > 0 && $entry['semester'] !== null)->values());
    }
}
