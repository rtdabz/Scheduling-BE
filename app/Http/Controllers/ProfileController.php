<?php

namespace App\Http\Controllers;

use App\Models\Faculty;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuthenticationAuditService;
use App\Services\FacultyLoadService;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Services\UserFacultyProfileService;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The signed-in account's own profile. Every role may change its name and
 * photo here; username, email, role and department stay with the VPAA's
 * Users page, since they decide what the account can reach.
 */
class ProfileController extends Controller
{
    /** A 300px JPEG from the picker is ~30 KB; this leaves headroom for PNGs. */
    private const MAX_PICTURE_LENGTH = 1_500_000;

    public function __construct(
        private readonly AuthenticationAuditService $audit,
        private readonly UserFacultyProfileService $facultyProfiles,
        private readonly FacultyLoadService $loads,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_initial' => ['nullable', 'string', 'size:1', 'alpha'],
            'last_name' => 'required|string|max:100',
            'suffix' => ['nullable', Rule::in(Faculty::NAME_SUFFIXES)],
            'profile_picture' => [
                'sometimes',
                'nullable',
                'string',
                'max:'.self::MAX_PICTURE_LENGTH,
                'regex:/^data:image\/(png|jpe?g|webp);base64,[A-Za-z0-9+\/=]+$/',
            ],
        ]);

        DB::transaction(function () use ($request, $user, $validated) {
            $user->update([
                'name' => User::composeDisplayName($validated),
                'first_name' => trim($validated['first_name']),
                'middle_initial' => filled($validated['middle_initial'] ?? null) ? strtoupper(trim($validated['middle_initial'])) : null,
                'last_name' => trim($validated['last_name']),
                'suffix' => $validated['suffix'] ?? null,
                'profile_picture' => array_key_exists('profile_picture', $validated) ? $validated['profile_picture'] : $user->profile_picture,
            ]);
            // Keeps the instructor record's name and photo in step, so
            // timetables and the Instructors page show the same person.
            $synced = $this->facultyProfiles->sync($user);

            $this->audit->record($request, 'profile_updated', $user, [
                'picture_changed' => $user->wasChanged('profile_picture'),
                'faculty_profile_synced' => $synced !== null,
            ]);
        });
        ApiCache::forgetGroups(['departments.index', 'faculty.index', 'initial.data']);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->payload($user->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $user->load(['department:id,department_name,department_code', 'program:id,name,code']);

        return [
            'user' => $user->only([
                'id', 'name', 'first_name', 'middle_initial', 'last_name', 'suffix',
                'username', 'email', 'role', 'profile_picture', 'google_email',
                'google_linked_at', 'last_login_at', 'created_at', 'department', 'program',
            ]),
            'suffixes' => Faculty::NAME_SUFFIXES,
            'teaching' => $this->teaching($user),
        ];
    }

    /**
     * The account's teaching load for the active semester, or null when the
     * account has no instructor record (a non-teaching secretary, say).
     *
     * @return array<string, mixed>|null
     */
    private function teaching(User $user): ?array
    {
        $faculty = $user->facultyProfile;
        if ($faculty === null) {
            return null;
        }

        $semester = Semester::query()->where('is_active', true)->first(['id', 'academic_year', 'semester']);
        // Totals come from the same service as the Instructors page so the two
        // never disagree about a person's load.
        $this->loads->decorate($faculty, $semester?->id);
        $assigned = (int) $faculty->assigned_units;
        $tier = SchedulingPolicy::facultyLoadTier($faculty, $assigned);

        return [
            'faculty_id' => $faculty->id,
            'employment_type' => $faculty->employment_type,
            'status' => $faculty->status,
            'semester' => $semester,
            'assigned_units' => $assigned,
            'basic_load' => SchedulingPolicy::facultyBasicLoad($faculty),
            'max_units' => (int) $faculty->max_units,
            'deload_units' => (int) $faculty->deload_units,
            'overload_units' => (int) $faculty->overload_units,
            'probono_units' => (int) $faculty->probono_units,
            'unit_ceiling' => SchedulingPolicy::facultyUnitCeiling($faculty),
            'tier' => $tier,
            'tier_label' => SchedulingPolicy::loadTierLabel($tier),
            'classes' => $semester ? $this->classes($faculty->id, $semester->id) : [],
        ];
    }

    /**
     * One entry per section and course, with every meeting it holds.
     *
     * @return list<array<string, mixed>>
     */
    private function classes(int $facultyId, int $semesterId): array
    {
        $rows = DB::table('schedules')
            ->join('courses', 'schedules.course_id', '=', 'courses.id')
            ->join('sections', 'schedules.section_id', '=', 'sections.id')
            ->leftJoin('rooms', 'schedules.room_id', '=', 'rooms.id')
            ->where('schedules.semester_id', $semesterId)
            ->where('schedules.faculty_id', $facultyId)
            ->whereIn('schedules.status', SchedulingPolicy::INSTRUCTOR_ASSIGNED_STATUSES)
            ->whereNull('schedules.deleted_at')
            ->orderBy('courses.course_code')
            ->orderBy('sections.section_name')
            ->get([
                'schedules.section_id', 'schedules.course_id', 'schedules.day',
                'schedules.start_time', 'schedules.end_time', 'schedules.mode',
                'courses.course_code', 'courses.course_name', 'courses.units',
                'sections.section_name', 'rooms.room_code',
            ]);

        return $rows
            ->groupBy(fn ($row) => "{$row->section_id}:{$row->course_id}")
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'section_id' => $first->section_id,
                    'course_id' => $first->course_id,
                    'course_code' => $first->course_code,
                    'course_name' => $first->course_name,
                    'units' => (int) $first->units,
                    'section_name' => $first->section_name,
                    'meetings' => $group
                        ->filter(fn ($row) => $row->day !== null)
                        ->map(fn ($row) => [
                            'day' => $row->day,
                            'start_time' => $row->start_time,
                            'end_time' => $row->end_time,
                            'room' => $row->room_code,
                            'mode' => $row->mode,
                        ])
                        ->unique(fn ($m) => "{$m['day']}|{$m['start_time']}|{$m['end_time']}")
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
