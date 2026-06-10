<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepartmentsController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // Logout and user info routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    // Department routes with VPAA-only access
    Route::middleware('role:vpaa')->group(function () {
        Route::resource('departments', DepartmentsController::class);
        Route::get('/departments/trash', [DepartmentsController::class, 'trash'])->name('departments.trash');
        Route::post('/departments/{id}/restore', [DepartmentsController::class, 'restore'])->name('departments.restore');
        Route::delete('/departments/{id}/force-delete', [DepartmentsController::class, 'forceDelete'])->name('departments.forceDelete');
    });
});
