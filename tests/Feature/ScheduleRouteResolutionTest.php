<?php

namespace Tests\Feature;

use App\Http\Controllers\ScheduleController;
use Illuminate\Http\Request;
use Tests\TestCase;

class ScheduleRouteResolutionTest extends TestCase
{
    public function test_batch_faculty_routes_are_not_captured_by_schedule_model_binding(): void
    {
        $router = app('router');

        $batchFaculty = $router->getRoutes()->match(
            Request::create('/api/schedules/batch-faculty', 'PATCH'),
        );
        $batchFacultyDone = $router->getRoutes()->match(
            Request::create('/api/schedules/batch-faculty-done', 'PATCH'),
        );

        $this->assertSame(ScheduleController::class.'@batchFaculty', $batchFaculty->getActionName());
        $this->assertSame(ScheduleController::class.'@batchFacultyDone', $batchFacultyDone->getActionName());
    }

    public function test_numeric_schedule_updates_still_resolve_to_the_update_action(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/schedules/123', 'PATCH'),
        );

        $this->assertSame(ScheduleController::class.'@update', $route->getActionName());
    }
}
