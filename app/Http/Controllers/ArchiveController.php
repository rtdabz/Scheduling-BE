<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Faculty;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\Sections;
use App\Models\Terms;
use App\Models\TimeslotOverride;
use App\Models\User;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class ArchiveController extends Controller
{
    /** @var array<string, class-string<Model>> */
    private const TYPES = [
        'users' => User::class,
        'departments' => Departments::class,
        'programs' => Program::class,
        'rooms' => Rooms::class,
        'faculties' => Faculty::class,
        'courses' => Course::class,
        'terms' => Terms::class,
        'sections' => Sections::class,
        'schedules' => Schedule::class,
        'schedule-splits' => ScheduleSplit::class,
        'timeslot-overrides' => TimeslotOverride::class,
    ];

    public function index(): JsonResponse
    {
        $records = collect(self::TYPES)->flatMap(function (string $modelClass, string $type) {
            return $modelClass::onlyTrashed()
                ->latest('deleted_at')
                ->get()
                ->map(fn (Model $record): array => [
                    'id' => $record->getKey(),
                    'type' => $type,
                    'label' => $this->label($type, $record),
                    'deleted_at' => $record->getAttribute('deleted_at'),
                ]);
        })->sortByDesc('deleted_at')->values();

        return response()->json(['data' => $records]);
    }

    public function restore(string $type, int $id): JsonResponse
    {
        $modelClass = self::TYPES[$type] ?? null;
        abort_if($modelClass === null, 404, 'Archive type not found.');

        $record = $modelClass::onlyTrashed()->findOrFail($id);

        try {
            $record->restore();
        } catch (QueryException) {
            return response()->json([
                'message' => 'This record cannot be restored because an active record now uses the same unique value.',
            ], 422);
        }

        ApiCache::forgetGroups([
            'departments.index',
            'rooms.index',
            'faculties.index',
            'courses.index',
            'sections.index',
            'sections.by_term',
            'sections.by_department',
            'terms.index',
            'terms.active',
            'initial.data',
        ]);

        return response()->json(['message' => 'Record restored successfully.']);
    }

    private function label(string $type, Model $record): string
    {
        return match ($type) {
            'users' => (string) $record->getAttribute('name'),
            'departments' => (string) $record->getAttribute('department_name'),
            'programs' => trim((string) $record->getAttribute('code').' - '.(string) $record->getAttribute('name')),
            'rooms' => (string) $record->getAttribute('room_code'),
            'faculties' => trim((string) $record->getAttribute('first_name').' '.(string) $record->getAttribute('last_name')),
            'courses' => trim((string) $record->getAttribute('course_code').' - '.(string) $record->getAttribute('course_name')),
            'terms' => trim((string) $record->getAttribute('academic_year').' '.(string) $record->getAttribute('semester')),
            'sections' => (string) $record->getAttribute('section_name'),
            'schedules' => 'Schedule #'.$record->getKey(),
            'schedule-splits' => 'Schedule split #'.$record->getKey(),
            'timeslot-overrides' => (string) $record->getAttribute('duration_minutes').' minute override',
        };
    }
}
