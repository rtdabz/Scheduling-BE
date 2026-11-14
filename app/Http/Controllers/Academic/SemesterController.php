<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Http\Requests\Semester\StoreSemesterRequest;
use App\Http\Requests\Semester\UpdateSemesterRequest;
use App\Models\Schedule;
use App\Models\ScheduleHistoryVersion;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Services\ScheduleSemesterArchiver;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SemesterController extends Controller
{
    public function index()
    {
        $semesters = Cache::remember(ApiCache::key('semesters.index'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Semester::orderBy('academic_year', 'desc')
            ->orderBy('semester', 'desc')
            ->get());

        return response()->json($semesters->map(fn (Semester $semester) => $this->withYearEligibility($semester)));
    }

    public function store(StoreSemesterRequest $request)
    {
        $validated = $request->validated();
        $academicYear = $validated['academic_year'] ?? null;
        if (! $academicYear) {
            $currentYear = now()->month >= 6 ? now()->year : now()->year - 1;
            $nextYear = $currentYear + 1;
            $academicYear = $currentYear.'-'.$nextYear;
        }

        $semester = DB::transaction(function () use ($academicYear, $validated) {
            $semesters = Semester::query()->orderBy('id')->lockForUpdate()->get();
            abort_if($semesters->contains(fn (Semester $semester) => $semester->academic_year === $academicYear
                && $semester->semester === $validated['semester']), 422, 'This academic semester already exists.');

            return Semester::create([
                'academic_year' => $academicYear,
                'semester' => $validated['semester'],
                'is_active' => false,
                'is_enabled' => $validated['semester'] !== 'summer',
            ]);
        }, 3);
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Semester created successfully.',
            'semester' => $this->withYearEligibility($semester),
        ], 201);
    }

    public function update(UpdateSemesterRequest $request, $id)
    {
        $validated = $request->validated();
        $semester = DB::transaction(function () use ($id, $validated) {
            // Lock in the same order as activation so availability and period identity
            // are checked against the active context that is actually being saved.
            $semesters = Semester::query()->orderBy('id')->lockForUpdate()->get();
            $semester = $semesters->firstWhere('id', (int) $id);
            abort_unless($semester, 404);

            if (isset($validated['academic_year']) && $validated['academic_year'] !== $semester->academic_year) {
                [$start, $end] = array_map('intval', explode('-', $validated['academic_year']));
                if ($end !== $start + 1) {
                    throw ValidationException::withMessages(['academic_year' => 'The academic year must span two consecutive years, e.g. 2026-2027.']);
                }
                if (! $this->canEditAcademicYear($semester)) {
                    throw ValidationException::withMessages(['academic_year' => 'The academic year is locked because this semester is active or has already been used. Create a new academic period instead.']);
                }
                if ($semesters->contains(fn (Semester $other) => $other->id !== $semester->id && $other->semester === $semester->semester && $other->academic_year === $validated['academic_year'])) {
                    throw ValidationException::withMessages(['academic_year' => 'Another semester already covers this academic year and semester.']);
                }
                $semester->academic_year = $validated['academic_year'];
            }

            if (array_key_exists('is_enabled', $validated) && (bool) $validated['is_enabled'] !== (bool) $semester->is_enabled) {
                $active = $semesters->where('is_active', true);
                if ($semester->semester !== 'summer') {
                    throw ValidationException::withMessages(['is_enabled' => 'Availability can only be changed for Summer.']);
                }
                if ($semester->is_active && ! $validated['is_enabled']) {
                    throw ValidationException::withMessages(['is_enabled' => 'Activate another semester before disabling Summer.']);
                }
                if ($active->count() !== 1 || $active->first()->semester !== '2nd') {
                    throw ValidationException::withMessages(['is_enabled' => 'Summer availability can be changed only while 2nd Semester is active.']);
                }
                $semester->is_enabled = (bool) $validated['is_enabled'];
            }

            if ($semester->isDirty()) {
                $semester->save();
            }

            return $semester;
        }, 3);
        ApiCache::forgetGroups([
            'semesters.index',
            'semesters.active',
            'sections.index',
            'sections.by_semester',
            'initial.data',
        ]);

        return response()->json([
            'message' => 'Semester updated successfully.',
            'semester' => $this->withYearEligibility($semester),
        ]);
    }

    public function show($id)
    {
        $semester = Semester::findOrFail($id);

        return response()->json($this->withYearEligibility($semester));
    }

    public function destroy($id)
    {
        DB::transaction(function () use ($id) {
            $semester = Semester::query()->lockForUpdate()->findOrFail($id);
            abort_if($semester->is_active, 400, 'Cannot archive the active academic semester. Please activate another semester first.');
            $semester->delete();
        }, 3);
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

    public function activate(Request $request, $id, ScheduleSemesterArchiver $archiver)
    {
        $validated = $request->validate([
            'expected_active_semester_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'expected_academic_year' => ['sometimes', 'required', 'string', 'regex:/^\d{4}-\d{4}$/'],
        ]);
        $actor = $request->user();
        [$semester, $versions] = DB::transaction(function () use ($id, $actor, $validated, $archiver) {
            $semesters = Semester::query()->orderBy('id')->lockForUpdate()->get();
            $semester = $semesters->firstWhere('id', (int) $id);
            abort_unless($semester, 404);
            $active = $semesters->where('is_active', true);
            abort_if($active->count() > 1, 409, 'Multiple active semesters are recorded. Resolve this configuration before activating a semester.');
            $previous = $active->first();

            if (isset($validated['expected_academic_year']) && $validated['expected_academic_year'] !== $semester->academic_year) {
                abort(409, 'The academic year changed. Refresh Settings and confirm the semester again.');
            }
            if ($semester->semester === 'summer' && ! $semester->is_enabled) {
                throw ValidationException::withMessages(['is_enabled' => 'Enable Summer before setting it as active.']);
            }
            if ($previous?->id === $semester->id) {
                return [$semester, collect()]; // Repeat requests do not create duplicate events or snapshots.
            }
            if (array_key_exists('expected_active_semester_id', $validated)
                && ($validated['expected_active_semester_id'] === null ? null : (int) $validated['expected_active_semester_id']) !== $previous?->id) {
                abort(409, 'The active semester changed. Refresh Settings and confirm the semester again.');
            }

            $versions = collect();
            if ($previous) {
                $schedules = Schedule::query()
                    ->where('semester_id', $previous->id)
                    ->whereIn('status', ScheduleSemesterArchiver::VPAA_APPROVED_STATUSES)
                    ->with(['academicSemester', 'section', 'course', 'faculty', 'room', 'department', 'split'])
                    ->lockForUpdate()
                    ->get();
                $versions = $archiver->archive($schedules, (int) $actor->id, (int) $previous->id);
            }

            $occurredAt = now();
            foreach (array_filter([$previous, $semester]) as $period) {
                $activating = $period->id === $semester->id;
                $period->is_active = $activating;
                $period->save();
                SchedulingAuditLog::create([
                    'user_id' => $actor->id,
                    'semester_id' => $period->id,
                    'action' => $activating ? 'semester_activated' : 'semester_ended',
                    'metadata' => [
                        'activated_semester_id' => $semester->id,
                        'semester' => $period->semester,
                        'academic_year' => $period->academic_year,
                        'actor_name' => $actor->name ?: $actor->username,
                        'history_version_ids' => $activating ? [] : $versions->pluck('id')->all(),
                    ],
                    'created_at' => $occurredAt,
                ]);
            }

            return [$semester, $versions];
        }, 3);
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
            'message' => 'Semester activated successfully. Existing schedules and Schedule History are retained.',
            'semester' => $this->withYearEligibility($semester),
            'history_version_ids' => $versions->pluck('id')->all(),
            'reset_section_count' => 0,
        ]);
    }

    public function active()
    {
        $semester = Cache::remember(ApiCache::key('semesters.active'), ApiCache::LOOKUP_TTL_SECONDS, fn () => Semester::where('is_active', true)->first());

        if (! $semester) {
            return response()->json(['message' => 'No active academic semester found.'], 404);
        }

        return response()->json($this->withYearEligibility($semester));
    }

    public function activationHistory()
    {
        $logs = SchedulingAuditLog::query()
            ->whereIn('action', ['semester_activated', 'semester_ended', 'schedule_semester_archived'])
            ->latest('created_at')->latest('id')
            ->get(['id', 'semester_id', 'user_id', 'action', 'metadata', 'created_at']);

        $semesterIds = $logs
            ->map(fn (SchedulingAuditLog $log) => $log->action === 'semester_ended' ? $log->semester_id : data_get($log->metadata, 'activated_semester_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $semesters = Semester::withTrashed()->whereIn('id', $semesterIds)->get()->keyBy('id');

        return response()->json($logs->map(function (SchedulingAuditLog $log) use ($semesters) {
            $semesterId = (int) ($log->action === 'semester_ended' ? $log->semester_id : data_get($log->metadata, 'activated_semester_id'));
            $semester = $semesters->get($semesterId);

            return [
                'id' => $log->id,
                'semester_id' => $semesterId,
                'semester' => data_get($log->metadata, 'semester', $semester?->semester),
                // Legacy logs did not record the year. Its current value is not evidence of the past year.
                'academic_year' => data_get($log->metadata, 'academic_year'),
                'action' => $log->action === 'semester_ended' ? 'Ended' : 'Activated',
                'changed_by' => data_get($log->metadata, 'actor_name') ?: ($log->recorded_actor['name'] ?? null),
                'occurred_at' => $log->created_at?->toISOString(),
                'identity_recorded' => data_get($log->metadata, 'academic_year') !== null,
            ];
        })->filter(fn (array $entry) => $entry['semester_id'] > 0)->values())
            ->header('X-Timezone', config('app.timezone'));
    }

    private function withYearEligibility(Semester $semester): Semester
    {
        return $semester->setAttribute('can_edit_academic_year', $this->canEditAcademicYear($semester));
    }

    private function canEditAcademicYear(Semester $semester): bool
    {
        return ! $semester->is_active
            && ! Sections::query()->where('semester_id', $semester->id)->exists()
            && ! Schedule::withTrashed()->where('semester_id', $semester->id)->exists()
            && ! ScheduleSubmission::query()->where('semester_id', $semester->id)->exists()
            && ! ScheduleHistoryVersion::query()->where('semester_id', $semester->id)->exists()
            && ! SchedulingAuditLog::query()->where(function ($query) use ($semester) {
                $query->where('semester_id', $semester->id)
                    ->orWhere('metadata->activated_semester_id', $semester->id);
            })->exists();
    }
}
