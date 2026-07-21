<?php

namespace App\Http\Controllers;

use App\Models\Departments;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\Sections;
use App\Models\Subjects;
use App\Models\Terms;
use App\Models\User;
use App\Services\FacultyLoadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InitialDataController extends Controller
{
    public function __construct(private readonly FacultyLoadService $facultyLoad)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $departmentId = $user->isVpaa() || $user->department_id === null
            ? null
            : (int) $user->department_id;
        $activeTerm = Terms::query()->where('is_active', true)->first();
        $activeTermId = $activeTerm?->id;

        $rooms = Rooms::query()
            ->with('department')
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->whereNull('department_id')
                    ->orWhere('department_id', $departmentId),
            ))
            ->get();

        $subjects = Subjects::query()
            ->with('department')
            ->where('status', 'active')
            ->when($departmentId !== null, fn (Builder $query) => $query->where(
                fn (Builder $scope) => $scope
                    ->where('department_id', $departmentId)
                    ->orWhere('subject_category', 'minor'),
            ))
            ->get();

        $sections = Sections::query()
            ->with(['department', 'term'])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->get();

        $schedules = Schedule::query()
            ->with(['term', 'section', 'subject', 'faculty', 'room', 'department'])
            ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($activeTermId !== null, fn (Builder $query) => $query->where('term_id', $activeTermId))
            ->latest()
            ->get();

        $departments = Departments::query()
            ->withCount(['rooms', 'sections', 'faculties'])
            ->with(['users' => fn ($query) => $query
                ->where('role', 'dean')
                ->select('id', 'name', 'department_id')])
            ->latest()
            ->get();

        return response()->json([
            'active_term' => $activeTerm,
            'rooms' => $rooms,
            'subjects' => $subjects,
            'faculties' => $this->facultyLoad->get($departmentId, $activeTermId),
            'sections' => $sections,
            'schedules' => $schedules,
            'departments' => $departments,
            'users' => User::query()
                ->with('department')
                ->when($departmentId !== null, fn (Builder $query) => $query->where('department_id', $departmentId))
                ->latest()
                ->get(),
        ]);
    }
}
