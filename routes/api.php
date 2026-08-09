<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PrimePower HRIS — REST API (v1)
|--------------------------------------------------------------------------
| Token-based (Sanctum). Obtain a token from POST /api/v1/login, then send
| it as `Authorization: Bearer <token>`.
*/

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // --- Module 1: Employee Information Management ---
        Route::get('employees/statistics', [EmployeeController::class, 'statistics']);
        Route::post('employees/{employeeId}/restore', [EmployeeController::class, 'restore'])
            ->whereNumber('employeeId');

        Route::apiResource('employees', EmployeeController::class);

        Route::get('employees/{employee}/documents', [EmployeeController::class, 'documents']);
        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument']);
        Route::delete('employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument']);
    });
});
