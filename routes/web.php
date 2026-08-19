<?php

use App\Http\Controllers\AttendanceExceptionController;
use App\Http\Controllers\AttendanceHistoryController;
use App\Http\Controllers\AttendanceReportController;
use App\Http\Controllers\CompensationController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveCalendarController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ReviewCycleController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SeparationController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\IntegrationController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\UserAccessController;
use App\Http\Controllers\ThirteenthMonthController;
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

        // 201-file documents inside their renewal window, or already lapsed.
        Route::get('credentials', [CredentialController::class, 'index'])->name('credentials');

        // Which 201 files are still missing a requirement.
        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');

        // Master data — the org structure employee records are filed against.
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
            ->name('departments.destroy');

        Route::get('positions', [PositionController::class, 'index'])->name('positions');
        Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
        Route::put('positions/{position}', [PositionController::class, 'update'])
            ->name('positions.update');
        Route::delete('positions/{position}', [PositionController::class, 'destroy'])
            ->name('positions.destroy');

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
        // The work calendar's holiday list — read by leave costing, attendance
        // status, and holiday pay, so it is not merely a reference table.
        Route::get('timekeeping/holidays', [HolidayController::class, 'index'])->name('holidays');
        Route::post('timekeeping/holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::put('timekeeping/holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
        Route::delete('timekeeping/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

        Route::post('timekeeping/schedules', [ScheduleController::class, 'storeSchedule'])->name('schedules.store');
        Route::delete('timekeeping/schedules/{schedule}', [ScheduleController::class, 'destroySchedule'])
            ->name('schedules.destroy');

        // Attendance summary reports
        Route::get('timekeeping/reports', [AttendanceReportController::class, 'index'])->name('reports');
        Route::get('timekeeping/reports/export', [AttendanceReportController::class, 'export'])->name('reports.export');

        // Automated exception checker — flags DTR records worth a second look.
        Route::get('timekeeping/exceptions', [AttendanceExceptionController::class, 'index'])->name('exceptions');

        // Who edited a DTR record, when, and what changed.
        Route::get('timekeeping/history', [AttendanceHistoryController::class, 'index'])->name('timekeeping.history');

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
        // Credits are earned per month of service, not handed out whole on
        // 1 January. Safe to re-run — it recomputes rather than adding.
        Route::post('leave/balances/accrue', [LeaveBalanceController::class, 'accrue'])
            ->name('leave.balances.accrue');

        Route::get('leave/calendar', LeaveCalendarController::class)->name('leave.calendar');

        Route::get('leave/types', [LeaveTypeController::class, 'index'])->name('leave.types');
        Route::post('leave/types', [LeaveTypeController::class, 'store'])->name('leave.types.store');
        Route::put('leave/types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave.types.update');
        Route::delete('leave/types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave.types.destroy');

        // Module 4 — Payroll & Compensation
        Route::get('payroll', [PayrollController::class, 'index'])->name('payroll');
        Route::post('payroll/periods', [PayrollController::class, 'storePeriod'])->name('payroll.periods.store');
        Route::post('payroll/periods/{payrollPeriod}/generate', [PayrollController::class, 'generate'])
            ->name('payroll.generate');

        Route::get('payroll/runs/{payrollRun}', [PayrollController::class, 'show'])->name('payroll.run');
        Route::post('payroll/runs/{payrollRun}/submit', [PayrollController::class, 'submit'])->name('payroll.submit');
        Route::post('payroll/runs/{payrollRun}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
        Route::post('payroll/runs/{payrollRun}/paid', [PayrollController::class, 'markPaid'])->name('payroll.paid');
        Route::post('payroll/runs/{payrollRun}/cancel', [PayrollController::class, 'cancel'])->name('payroll.cancel');

        Route::get('payroll/payslips', [PayslipController::class, 'index'])->name('payroll.payslips');
        Route::get('payroll/payslips/{payslip}', [PayslipController::class, 'show'])->name('payroll.payslip');

        // Statutory remittance and BIR reporting, read back from issued payslips.
        // Export sits above the index so /compliance/export is not swallowed.
        Route::get('payroll/compliance/export', [ComplianceController::class, 'export'])
            ->name('payroll.compliance.export');
        Route::get('payroll/compliance', [ComplianceController::class, 'index'])
            ->name('payroll.compliance');

        // 13th-month pay under PD 851, read back from finalised payslips.
        Route::get('payroll/13th-month/export', [ThirteenthMonthController::class, 'export'])
            ->name('payroll.thirteenth.export');
        Route::get('payroll/13th-month', [ThirteenthMonthController::class, 'index'])
            ->name('payroll.thirteenth');

        // Where an employee's rate is set, and the history behind it.
        Route::get('payroll/salaries', [SalaryController::class, 'index'])
            ->name('payroll.salaries');
        Route::post('payroll/salaries', [SalaryController::class, 'store'])
            ->name('payroll.salaries.store');
        Route::delete('payroll/salaries/{salaryAdjustment}', [SalaryController::class, 'destroy'])
            ->name('payroll.salaries.destroy');

        // Separation and final pay — the exit half of the lifecycle.
        Route::get('payroll/separations', [SeparationController::class, 'index'])
            ->name('payroll.separations');
        Route::post('payroll/separations', [SeparationController::class, 'store'])
            ->name('payroll.separations.store');
        Route::get('payroll/separations/{separation}', [SeparationController::class, 'show'])
            ->name('payroll.separation');
        Route::post('payroll/separations/{separation}/recompute', [SeparationController::class, 'recompute'])
            ->name('payroll.separations.recompute');
        Route::post('payroll/separations/{separation}/clearance', [SeparationController::class, 'clearance'])
            ->name('payroll.separations.clearance');
        Route::post('payroll/separations/{separation}/release', [SeparationController::class, 'release'])
            ->name('payroll.separations.release');

        Route::get('payroll/compensation', [CompensationController::class, 'index'])->name('payroll.compensation');
        Route::post('payroll/allowances', [CompensationController::class, 'storeAllowance'])->name('payroll.allowances.store');
        Route::delete('payroll/allowances/{allowance}', [CompensationController::class, 'destroyAllowance'])
            ->name('payroll.allowances.destroy');
        Route::post('payroll/loans', [CompensationController::class, 'storeLoan'])->name('payroll.loans.store');
        Route::post('payroll/loans/{loan}/cancel', [CompensationController::class, 'cancelLoan'])
            ->name('payroll.loans.cancel');

        // Module 5 — Performance Management
        Route::get('performance', [PerformanceController::class, 'index'])->name('performance');
        Route::get('performance/reviews/{review}', [PerformanceController::class, 'show'])
            ->name('performance.review');
        Route::put('performance/reviews/{review}', [PerformanceController::class, 'update'])
            ->name('performance.review.update');
        Route::post('performance/reviews/{review}/submit', [PerformanceController::class, 'submit'])
            ->name('performance.review.submit');
        Route::post('performance/reviews/{review}/acknowledge', [PerformanceController::class, 'acknowledge'])
            ->name('performance.review.acknowledge');
        Route::get('performance/employees/{employee}/history', [PerformanceController::class, 'history'])
            ->name('performance.history');

        Route::get('performance/cycles', [ReviewCycleController::class, 'index'])->name('performance.cycles');
        Route::post('performance/cycles', [ReviewCycleController::class, 'store'])->name('performance.cycles.store');
        Route::put('performance/cycles/{cycle}', [ReviewCycleController::class, 'update'])
            ->name('performance.cycles.update');
        Route::post('performance/cycles/{cycle}/rollout', [ReviewCycleController::class, 'rollout'])
            ->name('performance.cycles.rollout');
        Route::post('performance/cycles/{cycle}/close', [ReviewCycleController::class, 'close'])
            ->name('performance.cycles.close');

        Route::get('performance/kpis', [KpiController::class, 'index'])->name('performance.kpis');
        Route::post('performance/kpis', [KpiController::class, 'store'])->name('performance.kpis.store');
        Route::put('performance/kpis/{kpi}', [KpiController::class, 'update'])->name('performance.kpis.update');
        Route::delete('performance/kpis/{kpi}', [KpiController::class, 'destroy'])->name('performance.kpis.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
| Replaces the starter kit's profile page. Company-wide sections are
| administrator-only; Appearance and Security belong to every signed-in user.
*/
Route::middleware(['auth', 'verified'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', fn () => redirect()->route('settings.general'));

    Route::get('general', [SettingsController::class, 'general'])->name('general');
    Route::put('general', [SettingsController::class, 'updateGeneral'])->name('general.update');

    Route::get('appearance', [SettingsController::class, 'appearance'])->name('appearance');

    // Departments and positions moved to Employee Information, where HR
    // maintains them while filing people rather than while configuring the
    // system. Kept as a redirect so old links and bookmarks still land.
    Route::get('organization', fn () => redirect()->route('hr.departments'))
        ->name('organization');

    Route::get('notifications', [SettingsController::class, 'notifications'])->name('notifications');
    Route::put('notifications', [SettingsController::class, 'updateNotifications'])->name('notifications.update');

    Route::get('users', [UserAccessController::class, 'index'])->name('users');
    Route::post('users', [UserAccessController::class, 'store'])->name('users.store');
    Route::put('users/{user}/role', [UserAccessController::class, 'updateRole'])->name('users.role');
    Route::post('users/{user}/toggle', [UserAccessController::class, 'toggleActive'])->name('users.toggle');
    Route::post('users/{user}/reset-password', [UserAccessController::class, 'resetPassword'])->name('users.reset');

    Route::get('security', [SecurityController::class, 'index'])->name('security');
    Route::put('security/profile', [SecurityController::class, 'updateProfile'])->name('security.profile');
    Route::put('security/password', [SecurityController::class, 'updatePassword'])->name('security.password');
    Route::post('security/tokens/revoke-all', [SecurityController::class, 'revokeTokens'])->name('security.tokens.revokeAll');
    Route::delete('security/tokens/{token}', [SecurityController::class, 'revokeToken'])->name('security.tokens.revoke');
    Route::delete('security/account', [SecurityController::class, 'destroyAccount'])->name('security.account');

    Route::get('data', [SettingsController::class, 'data'])->name('data');
    Route::put('data', [SettingsController::class, 'updateData'])->name('data.update');
    Route::get('data/export/employees', [DataExportController::class, 'employees'])->name('data.export.employees');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
    Route::delete('integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
});

// The starter kit's profile page now lives under Settings > Security.
Route::middleware('auth')->get('/profile', fn () => redirect()->route('settings.security'));

require __DIR__.'/auth.php';
