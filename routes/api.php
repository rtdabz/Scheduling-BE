<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\DepartmentScheduleController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\Api\UserController;

use App\Http\Controllers\FacultyController;

use App\Http\Controllers\SubjectsController;

use App\Http\Controllers\SectionsController;

use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TermsController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Logout and user info routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/user', [UserController::class, 'index']);
    Route::post('/user', [UserController::class, 'store']);
    Route::put('/user/{user}', [UserController::class, 'update']);
    Route::delete('/user/{user}', [UserController::class, 'destroy']);

    // VPAA-only administration
    Route::middleware('role:vpaa')->group(function () {
        Route::resource('departments', DepartmentsController::class)->except(['index', 'show']);
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::delete('/departments/{id}/force-delete', [DepartmentsController::class, 'forceDelete'])->name('departments.forceDelete');
        Route::apiResource('terms', TermsController::class)->except(['index', 'show']);
        Route::patch('terms/{id}/activate', [TermsController::class, 'activate']);
    });

    // Common readable & scheduling administration routes across all roles.
    Route::middleware('role:vpaa,dean,secretary,program_head')->group(function () {
        Route::get('departments', [DepartmentsController::class, 'index']);
        Route::get('departments/{department}', [DepartmentsController::class, 'show']);

        // Department schedule-status (read: all 4 roles; write: owner dept only, enforced in controller)
        Route::get('departments/{id}/schedule-status', [DepartmentScheduleController::class, 'scheduleStatus']);
        Route::post('departments/{id}/submit-schedules', [DepartmentScheduleController::class, 'submitSchedules']);
        Route::post('departments/{id}/approve-by-dean', [DepartmentScheduleController::class, 'approveByDean']);
        Route::post('departments/{id}/return-by-dean', [DepartmentScheduleController::class, 'returnByDean']);
        Route::post('departments/{id}/approve-by-vpaa', [DepartmentScheduleController::class, 'approveByVpaa']);
        Route::post('departments/{id}/return-by-vpaa', [DepartmentScheduleController::class, 'returnByVpaa']);

        Route::get('rooms', [RoomsController::class, 'index']);
        Route::get('rooms/{room}', [RoomsController::class, 'show']);

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

        Route::get('subjects', [SubjectsController::class, 'index']);
        Route::get('subjects/{subject}', [SubjectsController::class, 'show']);

        Route::get('sections', [SectionsController::class, 'index']);
        Route::get('sections/term/{termId}', [SectionsController::class, 'byTerm']);
        Route::get('sections/department/{departmentId}', [SectionsController::class, 'byDepartment']);
        Route::get('sections/{section}', [SectionsController::class, 'show']);

        // Schedules Management
        Route::apiResource('schedules', ScheduleController::class);
        Route::get('schedules/term/{termId}', [ScheduleController::class, 'byTerm']);
        Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);

        // Faculties Read-only
        Route::get('faculties', [FacultyController::class, 'index']);
        Route::get('faculties/{faculty}', [FacultyController::class, 'show']);
    });

    // Subjects, Sections & Faculties — writable by VPAA, Secretary and Program Head.
    Route::middleware('role:vpaa,secretary,program_head')->group(function () {
        Route::post('subjects', [SubjectsController::class, 'store']);
        Route::match(['put', 'patch'], 'subjects/{subject}', [SubjectsController::class, 'update']);
        Route::delete('subjects/{subject}', [SubjectsController::class, 'destroy']);

        Route::post('sections', [SectionsController::class, 'store']);
        Route::match(['put', 'patch'], 'sections/{section}', [SectionsController::class, 'update']);
        Route::delete('sections/{section}', [SectionsController::class, 'destroy']);

        Route::post('faculties', [FacultyController::class, 'store']);
        Route::match(['put', 'patch'], 'faculties/{faculty}', [FacultyController::class, 'update']);
        Route::delete('faculties/{faculty}', [FacultyController::class, 'destroy']);
    });
});
