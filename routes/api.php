<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\Api\UserController;

use App\Http\Controllers\FacultyController;

use App\Http\Controllers\SubjectsController;

use App\Http\Controllers\SectionsController;

use App\Http\Controllers\ScheduleController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Logout and user info routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [UserController::class, 'index']);
    Route::post('/user', [UserController::class, 'store']);
    Route::put('/user/{user}', [UserController::class, 'update']);
    Route::delete('/user/{user}', [UserController::class, 'destroy']);

    // VPAA-only administration
    Route::middleware('role:vpaa')->group(function () {
        Route::resource('departments', DepartmentsController::class);
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::delete('/departments/{id}/force-delete', [DepartmentsController::class, 'forceDelete'])->name('departments.forceDelete');
        Route::apiResource('rooms', RoomsController::class);
        Route::patch('rooms/{room}/assign', [RoomsController::class, 'assign']);
        Route::apiResource('faculties', FacultyController::class);
        Route::apiResource('schedules', ScheduleController::class);
        Route::get('schedules/term/{termId}', [ScheduleController::class, 'byTerm']);
        Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);
    });

    // Subjects & Sections — readable by all scheduling roles.
    // Specific routes are declared before the {param} routes so they aren't
    // shadowed by wildcard model binding.
    Route::middleware('role:vpaa,dean,secretary,program_head')->group(function () {
        Route::get('subjects', [SubjectsController::class, 'index']);
        Route::get('subjects/{subject}', [SubjectsController::class, 'show']);

        Route::get('sections', [SectionsController::class, 'index']);
        Route::get('sections/term/{termId}', [SectionsController::class, 'byTerm']);
        Route::get('sections/department/{departmentId}', [SectionsController::class, 'byDepartment']);
        Route::get('sections/{section}', [SectionsController::class, 'show']);
    });

    // Subjects & Sections — writable by VPAA, Secretary and Program Head.
    Route::middleware('role:vpaa,secretary,program_head')->group(function () {
        Route::post('subjects', [SubjectsController::class, 'store']);
        Route::match(['put', 'patch'], 'subjects/{subject}', [SubjectsController::class, 'update']);
        Route::delete('subjects/{subject}', [SubjectsController::class, 'destroy']);

        Route::post('sections', [SectionsController::class, 'store']);
        Route::match(['put', 'patch'], 'sections/{section}', [SectionsController::class, 'update']);
        Route::delete('sections/{section}', [SectionsController::class, 'destroy']);
    });
});
