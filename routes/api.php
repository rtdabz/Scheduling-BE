<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\RoomsController;
use App\Http\Controllers\Api\UserController;

use App\Http\Controllers\FacultyController;

use App\Http\Controllers\SubjectsController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Logout and user info routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [UserController::class, 'index']);
    Route::post('/user', [UserController::class, 'store']);
    Route::put('/user/{user}', [UserController::class, 'update']);
    Route::delete('/user/{user}', [UserController::class, 'destroy']);

    // Department routes with VPAA-only access
    Route::middleware('role:vpaa')->group(function () {
        Route::resource('departments', DepartmentsController::class);
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::delete('/departments/{id}/force-delete', [DepartmentsController::class, 'forceDelete'])->name('departments.forceDelete');
        Route::apiResource('rooms', RoomsController::class);
        Route::patch('rooms/{room}/assign', [RoomsController::class, 'assign']);
        Route::apiResource('faculties', FacultyController::class);
        Route::apiResource('subjects', SubjectsController::class);
        Route::apiResource('schedules', ScheduleController::class);
    Route::get('schedules/term/{termId}', [ScheduleController::class, 'byTerm']);
    Route::get('schedules/section/{sectionId}', [ScheduleController::class, 'bySection']);
    });
});
