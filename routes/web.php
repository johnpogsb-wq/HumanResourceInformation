<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TimekeepingController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    |----------------------------------------------------------------------
    | Core Transaction 2 — Human Resource Information & Operations
    |----------------------------------------------------------------------
    */
    Route::prefix('hr')->name('hr.')->group(function () {
        // Module 1 — Employee Information Management
        Route::resource('employees', EmployeeController::class);

        Route::post('employees/{employee}/documents', [EmployeeController::class, 'storeDocument'])
            ->name('employees.documents.store');
        Route::get('employees/{employee}/documents/{document}/download', [EmployeeController::class, 'downloadDocument'])
            ->name('employees.documents.download');
        Route::delete('employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument'])
            ->name('employees.documents.destroy');

        // Module 2 — Timekeeping & Attendance
        Route::get('timekeeping', [TimekeepingController::class, 'index'])->name('timekeeping');
        Route::post('timekeeping', [TimekeepingController::class, 'store'])->name('timekeeping.store');
        Route::delete('timekeeping/{attendanceLog}', [TimekeepingController::class, 'destroy'])
            ->name('timekeeping.destroy');

        // Modules 3–5 — scaffolded routes, pages land next.
        Route::get('leave', [ModulePlaceholderController::class, 'leave'])->name('leave');
        Route::get('payroll', [ModulePlaceholderController::class, 'payroll'])->name('payroll');
        Route::get('performance', [ModulePlaceholderController::class, 'performance'])->name('performance');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
