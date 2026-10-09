<?php

use App\Http\Controllers\Academic\CoursesController;
use App\Http\Controllers\Academic\CurriculumController;
use App\Http\Controllers\Academic\DepartmentsController;
use App\Http\Controllers\Academic\DesignationController;
use App\Http\Controllers\Academic\ProgramController;
use App\Http\Controllers\Academic\SectionsController;
use App\Http\Controllers\Academic\SemesterController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\InstitutionSettingsController;
use App\Http\Controllers\Admin\RealtimeConfigController;
use App\Http\Controllers\Admin\SystemNotificationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Faculty\CourseTeachingAssignmentController;
use App\Http\Controllers\Faculty\FacultyAvailabilityController;
use App\Http\Controllers\Faculty\FacultyController;
use App\Http\Controllers\Faculty\InstructorAssignmentController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\Reports\VpaaDashboardController;
use App\Http\Controllers\Rooms\ProgramRoomController;
use App\Http\Controllers\Rooms\RoomRequestController;
use App\Http\Controllers\Rooms\RoomsController;
use App\Http\Controllers\Scheduling\DepartmentScheduleController;
use App\Http\Controllers\Scheduling\InitialDataController;
use App\Http\Controllers\Scheduling\ScheduleConflictController;
use App\Http\Controllers\Scheduling\ScheduleController;
use App\Http\Controllers\Scheduling\ScheduleHistoryController;
use App\Http\Controllers\Scheduling\ScheduleRecommendationController;
use App\Http\Controllers\Scheduling\ScheduleSplitController;
use App\Http\Controllers\Scheduling\SchedulingSettingsController;
use App\Http\Controllers\Scheduling\TimeslotController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:20,1');
Route::post('/auth/google/exchange', [AuthController::class, 'googleExchange'])->middleware('throttle:20,1');

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');
    Route::get('/initial-data', InitialDataController::class);
    Route::get('/institution-settings', [InstitutionSettingsController::class, 'show']);
    Route::get('/notifications', [SystemNotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [SystemNotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{notification}/read', [SystemNotificationController::class, 'markAsRead']);
    Route::get('/realtime-config', RealtimeConfigController::class);

    Route::middleware('role:vpaa')->group(function () {
        Route::get('/activity-log', [ActivityLogController::class, 'index']);
        Route::get('/vpaa/dashboard-insights', VpaaDashboardController::class);
        Route::get('/user', [UserController::class, 'index']);
        Route::get('/user/linkable-faculty', [UserController::class, 'linkableFaculty']);
        Route::post('/user', [UserController::class, 'store']);
        Route::put('/user/{user}', [UserController::class, 'update']);
        Route::delete('/user/{user}', [UserController::class, 'destroy']);
        Route::delete('/user/{user}/google-link', [UserController::class, 'unlinkGoogle']);
        Route::post('/user/{user}/invitation', [UserController::class, 'resendInvitation']);

        Route::get('/archives', [ArchiveController::class, 'index']);
        Route::post('/archives/{type}/{id}/restore', [ArchiveController::class, 'restore'])
            ->whereNumber('id');

        Route::apiResource('departments', DepartmentsController::class)->except(['index', 'show']);
        Route::apiResource('semesters', SemesterController::class)->except(['index', 'show']);
        Route::patch('/institution-settings', [InstitutionSettingsController::class, 'update']);
        Route::patch('semesters/{id}/activate', [SemesterController::class, 'activate']);
        Route::get('semesters/activation-history', [SemesterController::class, 'activationHistory']);
        Route::apiResource('programs', ProgramController::class)->only(['store', 'update', 'destroy']);
    });

    Route::middleware('capability:schedule.view')->get('/schedule-history', [ScheduleHistoryController::class, 'index']);

    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index']);
        Route::get('/reports/departments/{department}', [ReportsController::class, 'show'])
            ->whereNumber('department');
        Route::post('/reports/log-download', [ReportsController::class, 'logDownload']);
    });

    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('departments', [DepartmentsController::class, 'index']);

        Route::get('departments/schedule-overview', [DepartmentScheduleController::class, 'scheduleOverview']);

        Route::get('departments/{department}', [DepartmentsController::class, 'show']);

        Route::get('programs', [ProgramController::class, 'index']);

        Route::get('departments/{id}/schedule-status', [DepartmentScheduleController::class, 'scheduleStatus']);

        Route::get('schedule-submissions/{submission}/snapshot', [DepartmentScheduleController::class, 'submissionSnapshot'])
            ->whereNumber('submission');
        Route::get('schedule-submissions/{submission}/changes', [DepartmentScheduleController::class, 'submissionChanges'])
            ->whereNumber('submission');
        Route::get('schedule-submissions/{submission}/revision-diff', [DepartmentScheduleController::class, 'submissionRevisionDiff'])
            ->whereNumber('submission');

        Route::get('rooms', [RoomsController::class, 'index']);
        Route::get('rooms/{room}', [RoomsController::class, 'show']);

        Route::get('/curriculum', [CurriculumController::class, 'index']);
        Route::get('/curriculum/{curriculum}', [CurriculumController::class, 'show']);
        Route::get('/curriculum/{curriculum}/full', [CurriculumController::class, 'showWithCourses']);

        Route::middleware('role:vpaa')->group(function () {
            Route::post('rooms', [RoomsController::class, 'store']);
            Route::match(['put', 'patch'], 'rooms/{room}', [RoomsController::class, 'update']);
            Route::delete('rooms/{room}', [RoomsController::class, 'destroy']);
            Route::patch('rooms/{room}/assign', [RoomsController::class, 'assign']);
            Route::put('buildings', [RoomsController::class, 'renameBuilding']);
            Route::post('buildings/archive', [RoomsController::class, 'archiveBuilding']);
        });

        Route::get('semesters', [SemesterController::class, 'index']);
        Route::get('semesters/active', [SemesterController::class, 'active']);
        Route::get('semesters/{semester}', [SemesterController::class, 'show']);

        Route::get('courses', [CoursesController::class, 'index']);
        Route::get('courses/{course}', [CoursesController::class, 'show']);

        Route::get('sections', [SectionsController::class, 'index']);
        Route::get('sections/semester/{semesterId}', [SectionsController::class, 'bySemester']);
        Route::get('sections/department/{departmentId}', [SectionsController::class, 'byDepartment']);
        Route::get('sections/{section}', [SectionsController::class, 'show']);

        Route::get('schedules/pending-department-count', [ScheduleController::class, 'pendingDepartmentCount']);
        Route::get('schedules/semester/{semesterId}', [ScheduleController::class, 'bySemester']);
        Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);
        Route::apiResource('schedules', ScheduleController::class)->only(['index', 'show']);
        Route::apiResource('schedule-splits', ScheduleSplitController::class)->only(['index', 'show']);

        Route::get('designations', [DesignationController::class, 'index']);
        Route::get('designations/{designation}', [DesignationController::class, 'show']);

        Route::get('faculties', [FacultyController::class, 'index']);
        Route::get('faculties/{faculty}', [FacultyController::class, 'show']);
        Route::get('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'index']);
        Route::get('faculties/{faculty}/teaching-history', [FacultyController::class, 'teachingHistory']);
    });

    Route::middleware('capability:schedule.create')->group(function () {
        Route::post('schedules/batch/validate-splits', [ScheduleController::class, 'validateSplits']);
        Route::post('schedules/batch', [ScheduleController::class, 'batch']);
        Route::post('schedules', [ScheduleController::class, 'store']);
        Route::post('schedule-splits', [ScheduleSplitController::class, 'store']);
    });

    Route::middleware('capability:schedule.update')->group(function () {
        Route::patch('schedules/batch-status', [ScheduleController::class, 'batchStatus']);
        Route::match(['put', 'patch'], 'schedules/{schedule}', [ScheduleController::class, 'update'])
            ->whereNumber('schedule');
        Route::match(['put', 'patch'], 'schedule-splits/{scheduleSplit}', [ScheduleSplitController::class, 'update']);
    });

    Route::middleware('capability:schedule.delete')->group(function () {
        Route::delete('schedules/{schedule}', [ScheduleController::class, 'destroy']);
        Route::delete('schedule-splits/{scheduleSplit}', [ScheduleSplitController::class, 'destroy']);
    });

    Route::middleware('capability:schedule.submit')->post(
        'departments/{id}/submit-schedules',
        [DepartmentScheduleController::class, 'submitSchedules']
    );

    Route::middleware('capability:schedule.withdraw')->post(
        'departments/{id}/withdraw-submission',
        [DepartmentScheduleController::class, 'withdrawSubmission']
    );

    Route::middleware('capability:schedule.assign_instructor')->group(function () {
        Route::patch('schedules/batch-faculty', [ScheduleController::class, 'batchFaculty']);
        Route::patch('schedules/batch-faculty-done', [ScheduleController::class, 'batchFacultyDone']);
    });

    Route::middleware('capability:schedule.approve_dean')->group(function () {
        Route::post('departments/{id}/approve-by-dean', [DepartmentScheduleController::class, 'approveByDean']);
        Route::post('departments/{id}/return-by-dean', [DepartmentScheduleController::class, 'returnByDean']);
    });

    Route::middleware('capability:schedule.approve_vpaa')->group(function () {
        Route::post('departments/{id}/approve-by-vpaa', [DepartmentScheduleController::class, 'approveByVpaa']);
        Route::post('departments/{id}/return-by-vpaa', [DepartmentScheduleController::class, 'returnByVpaa']);
    });

    Route::middleware('capability:room.request,room.review_requests')->group(function () {
        Route::get('room-requests', [RoomRequestController::class, 'index']);
    });
    Route::get('room-requests/rooms/{room}/occupancy', [RoomRequestController::class, 'occupancy'])
        ->middleware('capability:room.request,room.review_requests,schedule.create')
        ->whereNumber('room');
    Route::middleware('capability:room.request')->group(function () {
        Route::post('room-requests', [RoomRequestController::class, 'store']);
        Route::post('room-requests/{roomRequest}/cancel', [RoomRequestController::class, 'cancel'])->whereNumber('roomRequest');
    });
    Route::middleware('capability:room.review_requests')->group(function () {
        Route::post('room-requests/{roomRequest}/approve', [RoomRequestController::class, 'approve'])->whereNumber('roomRequest');
        Route::post('room-requests/{roomRequest}/reject', [RoomRequestController::class, 'reject'])->whereNumber('roomRequest');
        Route::post('room-requests/{roomRequest}/revoke', [RoomRequestController::class, 'revoke'])->whereNumber('roomRequest');
    });

    Route::middleware('capability:schedule.view')->get('program-rooms', [ProgramRoomController::class, 'show']);
    Route::middleware('capability:room.assign_program')->put('program-rooms', [ProgramRoomController::class, 'update']);

    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('conflicts', [ScheduleConflictController::class, 'index']);
        Route::post('conflicts/{conflict}/review', [ScheduleConflictController::class, 'review']);
        Route::get('conflicts/resolved', [ScheduleConflictController::class, 'resolved']);
        Route::get('conflicts/rule-issues', [ScheduleConflictController::class, 'ruleIssues']);
        Route::get('conflicts/{conflict}/recommendations', [ScheduleConflictController::class, 'recommendations']);
        Route::post('conflicts/{conflict}/resolve', [ScheduleConflictController::class, 'resolve']);
    });

    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('timeslots', [TimeslotController::class, 'index']);
        Route::post('timeslots/generate', [TimeslotController::class, 'generateSlots']);
        Route::get('timeslots/available/{duration}', [TimeslotController::class, 'getAvailableSlots'])
            ->whereNumber('duration');
    });

    Route::middleware('role:vpaa')->group(function () {
        Route::patch('timeslots/settings', [TimeslotController::class, 'updateSettings']);
    });

    Route::middleware('capability:faculty.manage_designations')->group(function () {
        Route::get('designations/{designation}/holders', [DesignationController::class, 'holders']);
        Route::post('designations', [DesignationController::class, 'store']);
        Route::match(['put', 'patch'], 'designations/{designation}', [DesignationController::class, 'update']);
        Route::delete('designations/{designation}', [DesignationController::class, 'destroy']);
    });

    Route::middleware('role:vpaa')->group(function () {
        Route::post('faculties', [FacultyController::class, 'store']);
        Route::delete('faculties/{faculty}', [FacultyController::class, 'destroy']);
    });

    Route::middleware('capability:schedule.assign_instructor')->group(function () {
        Route::match(['put', 'patch'], 'faculties/{faculty}', [FacultyController::class, 'update']);
        Route::put('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'replace']);
    });

    Route::middleware('capability:schedule.assign_instructor')->group(function () {
        Route::get('instructor-assignments', [InstructorAssignmentController::class, 'index']);
        Route::delete('instructor-assignments/sections/{section}', [InstructorAssignmentController::class, 'clearSection']);
        Route::post('instructor-assignments/clear', [InstructorAssignmentController::class, 'clearSections']);
        Route::get('instructor-assignments/{schedule}/recommendations', [InstructorAssignmentController::class, 'recommendations']);
        Route::patch('instructor-assignments/{schedule}', [InstructorAssignmentController::class, 'update']);

    });

    Route::middleware('capability:schedule.assign_instructor_cross_department')->group(function () {
        Route::get('course-teaching-assignments', [CourseTeachingAssignmentController::class, 'index']);
        Route::post('course-teaching-assignments/batch', [CourseTeachingAssignmentController::class, 'batch']);
        Route::match(['put', 'patch'], 'course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'update']);
        Route::delete('course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'destroy']);
    });

    Route::middleware('capability:schedule.generate')->group(function () {
        Route::post('schedule-recommendations/available-slots', [ScheduleRecommendationController::class, 'availableSlots']);
        Route::post('schedule-recommendations/draft-review', [ScheduleRecommendationController::class, 'reviewDraft'])->middleware('throttle:30,1');
        Route::post('schedule-recommendations/year-level-preview', [ScheduleRecommendationController::class, 'yearLevelPreview'])->middleware('throttle:5,1');
        Route::post('schedule-recommendations/year-level-preview/queue', [ScheduleRecommendationController::class, 'queueYearLevelPreview'])->middleware('throttle:5,1');
        Route::get('schedule-recommendations/active-generation-run', [ScheduleRecommendationController::class, 'activeGenerationRun']);
        Route::get('schedule-recommendations/generation-runs/{runId}', [ScheduleRecommendationController::class, 'generationRun']);
        Route::post('schedule-recommendations/generation-runs/{runId}/cancel', [ScheduleRecommendationController::class, 'cancelGenerationRun']);
        Route::get('scheduling-settings', [SchedulingSettingsController::class, 'show']);
        Route::patch('scheduling-settings', [SchedulingSettingsController::class, 'update']);
    });

    Route::middleware('capability:schedule.create')->group(function () {
        Route::post('courses', [CoursesController::class, 'store']);
        Route::match(['put', 'patch'], 'courses/{course}', [CoursesController::class, 'update']);
        Route::delete('courses/{course}', [CoursesController::class, 'destroy']);

        Route::post('sections', [SectionsController::class, 'store']);
        Route::post('sections/batch', [SectionsController::class, 'batchStore']);
        Route::post('sections/assign-curriculum', [SectionsController::class, 'assignCurriculumToYearLevel']);
        Route::match(['put', 'patch'], 'sections/{section}', [SectionsController::class, 'update']);
        Route::delete('sections/{section}', [SectionsController::class, 'destroy']);

    });

    Route::middleware('capability:curriculum.manage')->group(function () {
        Route::post('curriculum/{curriculum}/courses', [CurriculumController::class, 'attachCourse']);
        Route::post('curriculum/{curriculum}/courses/batch', [CurriculumController::class, 'attachCoursesBatch']);
        Route::post('curriculum/{curriculum}/courses/batch-create', [CurriculumController::class, 'batchCreateAndAttachCourses']);
        Route::delete('curriculum/{curriculum}/courses/{course}', [CurriculumController::class, 'detachCourse']);
        Route::post('/curriculum', [CurriculumController::class, 'store']);
        Route::match(['put', 'patch'], '/curriculum/{curriculum}', [CurriculumController::class, 'update']);
        Route::delete('/curriculum/{curriculum}', [CurriculumController::class, 'destroy']);
        Route::post('/curriculum/{curriculum}/duplicate', [CurriculumController::class, 'duplicate']);
        Route::patch('/curriculum/{curriculum}/status', [CurriculumController::class, 'updateStatus']);
    });
});
