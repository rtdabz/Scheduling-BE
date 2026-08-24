<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\DepartmentScheduleController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProgramController;

use App\Http\Controllers\FacultyAvailabilityController;
use App\Http\Controllers\FacultyController;

use App\Http\Controllers\CoursesController;
use App\Http\Controllers\CourseTeachingAssignmentController;

use App\Http\Controllers\SectionsController;

use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleSplitController;
use App\Http\Controllers\ScheduleRecommendationController;
use App\Http\Controllers\InstructorAssignmentController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\InitialDataController;
use App\Http\Controllers\InstitutionSettingsController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\SchedulingSettingsController;
use App\Http\Controllers\TimeslotController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ScheduleHistoryController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:20,1');
Route::post('/auth/google/exchange', [AuthController::class, 'googleExchange'])->middleware('throttle:20,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Logout and user info routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/initial-data', InitialDataController::class);
    Route::get('/institution-settings', [InstitutionSettingsController::class, 'show']);
    Route::get('/notifications', [SystemNotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [SystemNotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{notification}/read', [SystemNotificationController::class, 'markAsRead']);

    // VPAA-only administration
    Route::middleware('role:vpaa')->group(function () {
        Route::get('/activity-log', [ActivityLogController::class, 'index']);
        Route::get('/user', [UserController::class, 'index']);
        Route::post('/user', [UserController::class, 'store']);
        Route::put('/user/{user}', [UserController::class, 'update']);
        Route::delete('/user/{user}', [UserController::class, 'destroy']);
        Route::delete('/user/{user}/google-link', [UserController::class, 'unlinkGoogle']);

        Route::apiResource('departments', DepartmentsController::class)->except(['index', 'show']);
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::delete('/departments/{id}/force-delete', [DepartmentsController::class, 'forceDelete'])->name('departments.forceDelete');
        Route::apiResource('terms', TermsController::class)->except(['index', 'show']);
        Route::patch('/institution-settings', [InstitutionSettingsController::class, 'update']);
        Route::patch('terms/{id}/activate', [TermsController::class, 'activate']);
        Route::apiResource('programs', ProgramController::class)->only(['store', 'update', 'destroy']);
    });

    Route::middleware('role:vpaa,dean,secretary,program_head')->get('/schedule-history', [ScheduleHistoryController::class, 'index']);

    // Common readable & scheduling administration routes across all roles.
    Route::middleware('role:vpaa,dean,secretary,program_head')->group(function () {
        Route::get('departments', [DepartmentsController::class, 'index']);
        Route::get('departments/{department}', [DepartmentsController::class, 'show']);

        // Which program an instructor or a major belongs to decides who may teach
        // it, so every role that maintains faculty or courses reads this list.
        Route::get('programs', [ProgramController::class, 'index']);

        // Department schedule-status (read: all 4 roles; write: owner dept only, enforced in controller)
        Route::get('departments/{id}/schedule-status', [DepartmentScheduleController::class, 'scheduleStatus']);
        Route::post('departments/{id}/submit-schedules', [DepartmentScheduleController::class, 'submitSchedules']);
        Route::post('departments/{id}/withdraw-submission', [DepartmentScheduleController::class, 'withdrawSubmission']);
        Route::post('departments/{id}/approve-by-dean', [DepartmentScheduleController::class, 'approveByDean']);
        Route::post('departments/{id}/return-by-dean', [DepartmentScheduleController::class, 'returnByDean']);
        Route::post('departments/{id}/approve-by-vpaa', [DepartmentScheduleController::class, 'approveByVpaa']);
        Route::post('departments/{id}/return-by-vpaa', [DepartmentScheduleController::class, 'returnByVpaa']);

        Route::get('rooms', [RoomsController::class, 'index']);
        Route::get('rooms/{room}', [RoomsController::class, 'show']);

        // Curriculum read
        Route::get('/curriculum', [CurriculumController::class, 'index']);
        Route::get('/curriculum/{curriculum}', [CurriculumController::class, 'show']);
        Route::get('/curriculum/{curriculum}/full', [CurriculumController::class, 'showWithCourses']);

        // Rooms management — restricted to authorized administrators (VPAA and Dean)
        Route::middleware('role:vpaa,dean')->group(function () {
            Route::post('rooms', [RoomsController::class, 'store']);
            Route::match(['put', 'patch'], 'rooms/{room}', [RoomsController::class, 'update']);
            Route::delete('rooms/{room}', [RoomsController::class, 'destroy']);
            Route::patch('rooms/{room}/assign', [RoomsController::class, 'assign']);
        });

        Route::get('terms', [TermsController::class, 'index']);
        Route::get('terms/active', [TermsController::class, 'active']);
        Route::get('terms/{term}', [TermsController::class, 'show']);

        Route::get('courses', [CoursesController::class, 'index']);
        Route::get('courses/{course}', [CoursesController::class, 'show']);

        Route::get('sections', [SectionsController::class, 'index']);
        Route::get('sections/term/{termId}', [SectionsController::class, 'byTerm']);
        Route::get('sections/department/{departmentId}', [SectionsController::class, 'byDepartment']);
        Route::get('sections/{section}', [SectionsController::class, 'show']);

        // Schedules Management
        Route::post('schedules/batch/validate-splits', [ScheduleController::class, 'validateSplits']);
        Route::post('schedules/batch', [ScheduleController::class, 'batch']);
        Route::patch('schedules/batch-status', [ScheduleController::class, 'batchStatus']);
        Route::patch('schedules/batch-faculty', [ScheduleController::class, 'batchFaculty']);
        Route::patch('schedules/batch-faculty-done', [ScheduleController::class, 'batchFacultyDone']);
        Route::get('schedules/pending-department-count', [ScheduleController::class, 'pendingDepartmentCount']);
        Route::get('schedules/term/{termId}', [ScheduleController::class, 'byTerm']);
        Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);
        Route::apiResource('schedules', ScheduleController::class);
        Route::apiResource('schedule-splits', ScheduleSplitController::class);

        // Faculties Read-only
        Route::get('faculties', [FacultyController::class, 'index']);
        Route::get('faculties/{faculty}', [FacultyController::class, 'show']);
        Route::get('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'index']);
    });

    Route::middleware('role:vpaa,dean,secretary,program_head,admin')->group(function () {
        Route::get('timeslots', [TimeslotController::class, 'index']);
        Route::post('timeslots/generate', [TimeslotController::class, 'generateSlots']);
        Route::get('timeslots/available/{duration}', [TimeslotController::class, 'getAvailableSlots'])
            ->whereNumber('duration');
    });

    Route::middleware('role:vpaa,admin')->group(function () {
        Route::patch('timeslots/settings', [TimeslotController::class, 'updateSettings']);
        Route::post('timeslots/overrides', [TimeslotController::class, 'storeOverride']);
        Route::match(['put', 'patch'], 'timeslots/overrides/{id}', [TimeslotController::class, 'updateOverride'])
            ->whereNumber('id');
        Route::delete('timeslots/overrides/{id}', [TimeslotController::class, 'destroyOverride'])
            ->whereNumber('id');
    });

    // Instructor roster — the VPAA owns who exists, so creating and deleting an
    // instructor profile is VPAA-only.
    Route::middleware('role:vpaa')->group(function () {
        Route::post('faculties', [FacultyController::class, 'store']);
        Route::delete('faculties/{faculty}', [FacultyController::class, 'destroy']);
    });

    // The secretary maintains the teaching load allowances and the weekly
    // availability windows they schedule against; FacultyController::update
    // narrows a secretary to the load fields alone.
    Route::middleware('role:vpaa,secretary')->group(function () {
        Route::match(['put', 'patch'], 'faculties/{faculty}', [FacultyController::class, 'update']);
        Route::put('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'replace']);
    });

    // Courses & Sections — writable by VPAA, Secretary and Program Head.
    // Recommendation workflow is limited to schedule-building roles.
    Route::middleware('role:secretary,program_head')->group(function () {
        Route::get('instructor-assignments', [InstructorAssignmentController::class, 'index']);
        Route::patch('instructor-assignments/{schedule}', [InstructorAssignmentController::class, 'update']);

        // Which college teaches a course, when it is not the one that owns it.
        // Any secretary or program head may decide this for a delegable course;
        // majors are refused by the controller.
        Route::get('course-teaching-assignments', [CourseTeachingAssignmentController::class, 'index']);
        Route::post('course-teaching-assignments/batch', [CourseTeachingAssignmentController::class, 'batch']);
        Route::match(['put', 'patch'], 'course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'update']);
        Route::delete('course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'destroy']);

        Route::post('schedule-recommendations/auto-generate', [ScheduleRecommendationController::class, 'autoGenerateAndApply']);
        Route::post('schedule-recommendations/preview', [ScheduleRecommendationController::class, 'preview']);
        Route::post('schedule-recommendations/preview/queue', [ScheduleRecommendationController::class, 'queuePreview'])->middleware('throttle:10,1');
        Route::post('schedule-recommendations/year-level-preview', [ScheduleRecommendationController::class, 'yearLevelPreview'])->middleware('throttle:5,1');
        Route::post('schedule-recommendations/year-level-preview/queue', [ScheduleRecommendationController::class, 'queueYearLevelPreview'])->middleware('throttle:5,1');
        Route::get('schedule-recommendations/generation-runs/{runId}', [ScheduleRecommendationController::class, 'generationRun']);
        Route::post('schedule-recommendations/select', [ScheduleRecommendationController::class, 'select']);
        Route::post('schedule-recommendations/recommend-split', [ScheduleRecommendationController::class, 'recommendSplit']);
        Route::get('schedule-recommendations', [ScheduleRecommendationController::class, 'index']);
        Route::post('schedule-recommendations', [ScheduleRecommendationController::class, 'store']);
        Route::get('schedule-recommendations/{scheduleRecommendation}', [ScheduleRecommendationController::class, 'show']);
        Route::post('schedule-recommendations/{scheduleRecommendation}/review', [ScheduleRecommendationController::class, 'review']);
        Route::post('schedule-recommendations/{scheduleRecommendation}/accept', [ScheduleRecommendationController::class, 'accept']);
        Route::post('schedule-recommendations/{scheduleRecommendation}/reject', [ScheduleRecommendationController::class, 'reject']);
        Route::get('scheduling-settings', [SchedulingSettingsController::class, 'show']);
        Route::patch('scheduling-settings', [SchedulingSettingsController::class, 'update']);
        Route::post('curriculum/{curriculum}/courses', [CurriculumController::class, 'attachCourse']);
        Route::post('curriculum/{curriculum}/courses/batch', [CurriculumController::class, 'attachCoursesBatch']);
        Route::post('curriculum/{curriculum}/courses/batch-create', [CurriculumController::class, 'batchCreateAndAttachCourses']);
        Route::delete('curriculum/{curriculum}/courses/{course}', [CurriculumController::class, 'detachCourse']);
    });

    Route::middleware('role:vpaa,secretary,program_head')->group(function () {
        Route::post('courses', [CoursesController::class, 'store']);
        Route::match(['put', 'patch'], 'courses/{course}', [CoursesController::class, 'update']);
        Route::delete('courses/{course}', [CoursesController::class, 'destroy']);

        Route::post('sections', [SectionsController::class, 'store']);
        Route::post('sections/batch', [SectionsController::class, 'batchStore']);
        Route::match(['put', 'patch'], 'sections/{section}', [SectionsController::class, 'update']);
        Route::delete('sections/{section}', [SectionsController::class, 'destroy']);

        // Curriculum write
        Route::post('/curriculum', [CurriculumController::class, 'store']);
        Route::match(['put', 'patch'], '/curriculum/{curriculum}', [CurriculumController::class, 'update']);
        Route::delete('/curriculum/{curriculum}', [CurriculumController::class, 'destroy']);
        Route::post('/curriculum/{curriculum}/duplicate', [CurriculumController::class, 'duplicate']);
        Route::patch('/curriculum/{curriculum}/status', [CurriculumController::class, 'updateStatus']);
    });
});
