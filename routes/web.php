<?php

use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveCalendarController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\ModulePlaceholderController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScheduleController;
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
        Route::post('timekeeping/import', [TimekeepingController::class, 'import'])
            ->name('timekeeping.import');
        Route::delete('timekeeping/{attendanceLog}', [TimekeepingController::class, 'destroy'])
            ->name('timekeeping.destroy');

        // Overtime filing and approval
        Route::get('timekeeping/overtime', [OvertimeController::class, 'index'])->name('overtime');
        Route::post('timekeeping/overtime', [OvertimeController::class, 'store'])->name('overtime.store');
        Route::put('timekeeping/overtime/{overtimeRequest}', [OvertimeController::class, 'update'])
            ->name('overtime.update');
        Route::post('timekeeping/overtime/{overtimeRequest}/decide', [OvertimeController::class, 'decide'])
            ->name('overtime.decide');
        Route::post('timekeeping/overtime/{overtimeRequest}/cancel', [OvertimeController::class, 'cancel'])
            ->name('overtime.cancel');

        // Shifts and employee schedules
        Route::get('timekeeping/schedules', [ScheduleController::class, 'index'])->name('schedules');
        Route::post('timekeeping/shifts', [ScheduleController::class, 'storeShift'])->name('shifts.store');
        Route::put('timekeeping/shifts/{shift}', [ScheduleController::class, 'updateShift'])->name('shifts.update');
        Route::delete('timekeeping/shifts/{shift}', [ScheduleController::class, 'destroyShift'])->name('shifts.destroy');
        Route::post('timekeeping/schedules', [ScheduleController::class, 'storeSchedule'])->name('schedules.store');
        Route::delete('timekeeping/schedules/{schedule}', [ScheduleController::class, 'destroySchedule'])
            ->name('schedules.destroy');

        // Attendance summary reports
        Route::get('timekeeping/reports', [AttendanceReportController::class, 'index'])->name('reports');
        Route::get('timekeeping/reports/export', [AttendanceReportController::class, 'export'])->name('reports.export');

        // Module 3 — Leave & Absence
        Route::get('leave', [LeaveController::class, 'index'])->name('leave');
        Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
        Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->name('leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->name('leave.reject');
        Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
        Route::get('leave/{leaveRequest}/attachment', [LeaveController::class, 'attachment'])
            ->name('leave.attachment');

        Route::get('leave/balances', [LeaveBalanceController::class, 'index'])->name('leave.balances');
        Route::post('leave/balances', [LeaveBalanceController::class, 'update'])->name('leave.balances.update');
        Route::post('leave/balances/allocate', [LeaveBalanceController::class, 'allocate'])
            ->name('leave.balances.allocate');

        Route::get('leave/calendar', LeaveCalendarController::class)->name('leave.calendar');

        Route::get('leave/types', [LeaveTypeController::class, 'index'])->name('leave.types');
        Route::post('leave/types', [LeaveTypeController::class, 'store'])->name('leave.types.store');
        Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave.types.update');
        Route::delete('leave/types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave.types.destroy');

        // Modules 4–5 — scaffolded routes, pages land next.
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
