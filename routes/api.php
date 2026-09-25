<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CoursesController;
use App\Http\Controllers\CourseTeachingAssignmentController;
use App\Http\Controllers\CurriculumController;
use App\Http\Controllers\DepartmentScheduleController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\FacultyAvailabilityController;
use App\Http\Controllers\FacultyController;
use App\Http\Controllers\InitialDataController;
use App\Http\Controllers\InstitutionSettingsController;
use App\Http\Controllers\InstructorAssignmentController;
use App\Http\Controllers\RealtimeConfigController;
use App\Http\Controllers\ProgramRoomController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RoomRequestController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\ScheduleConflictController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleHistoryController;
use App\Http\Controllers\ScheduleRecommendationController;
use App\Http\Controllers\ScheduleSplitController;
use App\Http\Controllers\SchedulingSettingsController;
use App\Http\Controllers\SectionsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\TimeslotController;
use App\Http\Controllers\VpaaDashboardController;
use Illuminate\Support\Facades\Route;

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
    // The signed-in account's own name, photo and teaching load.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');
    Route::get('/initial-data', InitialDataController::class);
    Route::get('/institution-settings', [InstitutionSettingsController::class, 'show']);
    Route::get('/notifications', [SystemNotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [SystemNotificationController::class, 'markAllAsRead']);
    Route::patch('/notifications/{notification}/read', [SystemNotificationController::class, 'markAsRead']);
    Route::get('/realtime-config', RealtimeConfigController::class);

    // VPAA-only administration
    Route::middleware('role:vpaa')->group(function () {
        Route::get('/activity-log', [ActivityLogController::class, 'index']);
        // Institution-wide aggregates for the VPAA dashboard. Separate from
        // /initial-data because that payload caps its schedule rows, which
        // silently understates campus-wide utilisation and hides clashes.
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
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::apiResource('semesters', SemesterController::class)->except(['index', 'show']);
        Route::patch('/institution-settings', [InstitutionSettingsController::class, 'update']);
        Route::patch('semesters/{id}/activate', [SemesterController::class, 'activate']);
        Route::get('semesters/activation-history', [SemesterController::class, 'activationHistory']);
        Route::apiResource('programs', ProgramController::class)->only(['store', 'update', 'destroy']);
    });

    Route::middleware('capability:schedule.view')->get('/schedule-history', [ScheduleHistoryController::class, 'index']);

    // Printed Department Schedule and Teaching Load. Department scoping is
    // enforced in the controller, so a granted capability is enough to reach it.
    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index']);
        Route::get('/reports/departments/{department}', [ReportsController::class, 'show'])
            ->whereNumber('department');
    });

    // Common readable & scheduling administration routes across all roles.
    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('departments', [DepartmentsController::class, 'index']);

        // Registered before `departments/{department}` so the literal segment is
        // not matched as a department id.
        Route::get('departments/schedule-overview', [DepartmentScheduleController::class, 'scheduleOverview']);

        Route::get('departments/{department}', [DepartmentsController::class, 'show']);

        // Which program an instructor or a major belongs to decides who may teach
        // it, so every role that maintains faculty or courses reads this list.
        Route::get('programs', [ProgramController::class, 'index']);

        // Department schedule-status is readable by all three operational roles.
        Route::get('departments/{id}/schedule-status', [DepartmentScheduleController::class, 'scheduleStatus']);

        Route::get('rooms', [RoomsController::class, 'index']);
        Route::get('rooms/{room}', [RoomsController::class, 'show']);

        // Curriculum read
        Route::get('/curriculum', [CurriculumController::class, 'index']);
        Route::get('/curriculum/{curriculum}', [CurriculumController::class, 'show']);
        Route::get('/curriculum/{curriculum}/full', [CurriculumController::class, 'showWithCourses']);

        // Room management is institution-wide administration owned by VPAA.
        Route::middleware('role:vpaa')->group(function () {
            Route::post('rooms', [RoomsController::class, 'store']);
            Route::match(['put', 'patch'], 'rooms/{room}', [RoomsController::class, 'update']);
            Route::delete('rooms/{room}', [RoomsController::class, 'destroy']);
            Route::patch('rooms/{room}/assign', [RoomsController::class, 'assign']);
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

        // Schedule reads remain available to all three operational roles.
        Route::get('schedules/pending-department-count', [ScheduleController::class, 'pendingDepartmentCount']);
        Route::get('schedules/semester/{semesterId}', [ScheduleController::class, 'bySemester']);
        Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);
        Route::apiResource('schedules', ScheduleController::class)->only(['index', 'show']);
        Route::apiResource('schedule-splits', ScheduleSplitController::class)->only(['index', 'show']);

        // Designations are a lookup every roster screen renders a badge from,
        // so the list follows the same read gate as the roster itself.
        Route::get('designations', [DesignationController::class, 'index']);
        Route::get('designations/{designation}', [DesignationController::class, 'show']);

        // Faculties Read-only
        Route::get('faculties', [FacultyController::class, 'index']);
        Route::get('faculties/{faculty}', [FacultyController::class, 'show']);
        Route::get('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'index']);
        Route::get('faculties/{faculty}/teaching-history', [FacultyController::class, 'teachingHistory']);
    });

    // Schedule mutation routes are separated by capability so a user can be
    // granted instructor assignment without also becoming a schedule author.
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

    // Room requests: a department borrowing another department's vacant room
    // for weekly windows of a semester. Both lists are gated by capability, never
    // role, so the role defaults in config/capabilities.php decide who may ask,
    // who may decide (the owning department) and who only watches (the VPAA).
    Route::middleware('capability:room.request,room.review_requests,room.view_all_requests')->group(function () {
        Route::get('room-requests', [RoomRequestController::class, 'index']);
        Route::get('room-requests/rooms/{room}/occupancy', [RoomRequestController::class, 'occupancy'])->whereNumber('room');
    });
    Route::middleware('capability:room.request')->group(function () {
        Route::post('room-requests', [RoomRequestController::class, 'store']);
        Route::post('room-requests/{roomRequest}/cancel', [RoomRequestController::class, 'cancel'])->whereNumber('roomRequest');
    });
    Route::middleware('capability:room.review_requests')->group(function () {
        Route::post('room-requests/{roomRequest}/approve', [RoomRequestController::class, 'approve'])->whereNumber('roomRequest');
        Route::post('room-requests/{roomRequest}/reject', [RoomRequestController::class, 'reject'])->whereNumber('roomRequest');
        Route::post('room-requests/{roomRequest}/revoke', [RoomRequestController::class, 'revoke'])->whereNumber('roomRequest');
    });

    // Program rooms: which program each of the department's rooms belongs to,
    // and how the department's programs share them. Everyone who schedules
    // reads it; only the secretary changes it.
    Route::middleware('capability:schedule.view')->get('program-rooms', [ProgramRoomController::class, 'show']);
    Route::middleware('capability:room.assign_program')->put('program-rooms', [ProgramRoomController::class, 'update']);

    // The three timeslot reads describe the grid every scheduling screen draws
    // against; none of them writes. Authorization is capability-based, so new
    // operational roles do not need to be added to this route.
    // Conflict inbox and resolution workflow. Conflicts are derived on every
    // read, so `{conflict}` is a content identifier -- `rule:lowId:highId` --
    // rather than a row in a table. Reading the list only needs schedule.view;
    // the controller checks the capability and the department the action itself
    // needs, because a move, a reassignment and an override are not the same
    // permission.
    Route::middleware('capability:schedule.view')->group(function () {
        Route::get('conflicts', [ScheduleConflictController::class, 'index']);
        Route::post('conflicts/{conflict}/resolve', [ScheduleConflictController::class, 'resolve']);
        Route::post('conflicts/{conflict}/override', [ScheduleConflictController::class, 'override']);
    });

    Route::middleware('capability:schedule.view')->group(function () {
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

    // Maintaining the designation list is its own capability rather than a role
    // gate, so which roles hold it is decided in config/capabilities.php.
    // Reads stay open to every scheduling role above.
    Route::middleware('capability:faculty.manage_designations')->group(function () {
        Route::post('designations', [DesignationController::class, 'store']);
        Route::match(['put', 'patch'], 'designations/{designation}', [DesignationController::class, 'update']);
        Route::delete('designations/{designation}', [DesignationController::class, 'destroy']);
    });

    // Instructor roster — the VPAA owns who exists, so creating and deleting an
    // instructor profile is VPAA-only.
    Route::middleware('role:vpaa')->group(function () {
        Route::post('faculties', [FacultyController::class, 'store']);
        Route::delete('faculties/{faculty}', [FacultyController::class, 'destroy']);
    });

    // Teaching load allowances and the weekly availability windows the
    // scheduler places against are instructor-assignment data, so they follow
    // the assignment capability rather than a role name. Gating them on
    // 'role:vpaa,secretary' meant a Program Head the VPAA had granted every
    // capability still got a 403 from the editors their own Instructors page
    // renders. FacultyController::update narrows everyone but the VPAA -- who
    // owns the roster -- to the load fields alone.
    Route::middleware('capability:schedule.assign_instructor')->group(function () {
        Route::match(['put', 'patch'], 'faculties/{faculty}', [FacultyController::class, 'update']);
        Route::put('faculties/{faculty}/availabilities', [FacultyAvailabilityController::class, 'replace']);
    });

    // Courses & Sections — writable by VPAA, Secretary and Program Head.
    // Recommendation workflow is limited to schedule-building roles.
    Route::middleware('capability:schedule.assign_instructor')->group(function () {
        Route::get('instructor-assignments', [InstructorAssignmentController::class, 'index']);
        Route::delete('instructor-assignments/sections/{section}', [InstructorAssignmentController::class, 'clearSection']);
        Route::post('instructor-assignments/clear', [InstructorAssignmentController::class, 'clearSections']);
        Route::patch('instructor-assignments/{schedule}', [InstructorAssignmentController::class, 'update']);

    });

    // Which college teaches a course, when it is not the one that owns it.
    // Held apart from plain instructor assignment: an account may be trusted
    // with its own department's faculty without being trusted to move a course
    // between colleges. Majors are refused by the controller either way.
    Route::middleware('capability:schedule.assign_instructor_cross_department')->group(function () {
        Route::get('course-teaching-assignments', [CourseTeachingAssignmentController::class, 'index']);
        Route::post('course-teaching-assignments/batch', [CourseTeachingAssignmentController::class, 'batch']);
        Route::match(['put', 'patch'], 'course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'update']);
        Route::delete('course-teaching-assignments/{course}', [CourseTeachingAssignmentController::class, 'destroy']);
    });

    Route::middleware('capability:schedule.generate')->group(function () {
        Route::post('schedule-recommendations/auto-generate', [ScheduleRecommendationController::class, 'autoGenerateAndApply']);
        Route::post('schedule-recommendations/preview', [ScheduleRecommendationController::class, 'preview']);
        Route::post('schedule-recommendations/available-slots', [ScheduleRecommendationController::class, 'availableSlots']);
        Route::post('schedule-recommendations/preview/queue', [ScheduleRecommendationController::class, 'queuePreview'])->middleware('throttle:10,1');
        Route::post('schedule-recommendations/year-level-preview', [ScheduleRecommendationController::class, 'yearLevelPreview'])->middleware('throttle:5,1');
        Route::post('schedule-recommendations/year-level-preview/queue', [ScheduleRecommendationController::class, 'queueYearLevelPreview'])->middleware('throttle:5,1');
        Route::get('schedule-recommendations/active-generation-run', [ScheduleRecommendationController::class, 'activeGenerationRun']);
        Route::get('schedule-recommendations/generation-runs/{runId}', [ScheduleRecommendationController::class, 'generationRun']);
        Route::post('schedule-recommendations/generation-runs/{runId}/cancel', [ScheduleRecommendationController::class, 'cancelGenerationRun']);
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
    });

    Route::middleware('capability:schedule.create')->group(function () {
        Route::post('courses', [CoursesController::class, 'store']);
        Route::match(['put', 'patch'], 'courses/{course}', [CoursesController::class, 'update']);
        Route::delete('courses/{course}', [CoursesController::class, 'destroy']);

        Route::post('sections', [SectionsController::class, 'store']);
        Route::post('sections/batch', [SectionsController::class, 'batchStore']);
        // Registered before sections/{section} so "assign-curriculum" is never
        // captured as a section route-model binding.
        Route::post('sections/assign-curriculum', [SectionsController::class, 'assignCurriculumToYearLevel']);
        Route::match(['put', 'patch'], 'sections/{section}', [SectionsController::class, 'update']);
        Route::delete('sections/{section}', [SectionsController::class, 'destroy']);

    });

    // Curriculum authoring belongs to the department secretary that owns the
    // programs. The dean and the VPAA keep the read routes above -- they review
    // curricula but cannot mutate the records or their course placements.
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
