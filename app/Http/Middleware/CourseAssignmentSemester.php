<?php

namespace App\Http\Middleware;

use App\Models\Course;
use App\Models\Semester;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Serializes delegation writes with semester activation, including validation and notifications. */
class CourseAssignmentSemester
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            $semesters = Semester::query()->orderBy('id')->lockForUpdate()->get();
            $active = $semesters->where('is_active', true);
            abort_unless($active->count() === 1, 422, 'Activate an academic semester before assigning course teaching.');
            $validated = $request->validate(['expected_active_semester_id' => ['sometimes', 'required', 'integer', 'min:1']]);
            if (isset($validated['expected_active_semester_id'])) {
                abort_unless((int) $validated['expected_active_semester_id'] === (int) $active->first()->id,
                    409, 'The active semester changed. Refresh Course Assignment before saving.');
            }
            $request->attributes->set('course_assignment_semester', $active->first());
            // Route binding can precede the semester lock. Re-read its active projection under it.
            $course = $request->route('course');
            if ($course instanceof Course) {
                $course->refresh();
            }

            $response = $next($request);
            // Laravel's routing pipeline may render a controller exception before returning
            // through middleware. A rendered failure must still roll back the outer transaction.
            if ($response->getStatusCode() >= 400) {
                throw new HttpResponseException($response);
            }

            return $response;
        }, 3);
    }
}
