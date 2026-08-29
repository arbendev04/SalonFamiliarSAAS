<?php

use App\Http\Controllers\AttendanceAdjustmentController;
use App\Http\Controllers\AttendanceEventController;
use App\Http\Controllers\AttendanceRecordController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeScheduleController;
use App\Http\Controllers\EmploymentContractController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LaborRuleVersionController;
use App\Http\Controllers\LeaveRecordController;
use App\Http\Controllers\OvertimeRecordController;
use App\Http\Controllers\PayrollAdjustmentController;
use App\Http\Controllers\PayrollDeductionPlanController;
use App\Http\Controllers\PayrollInformationController;
use App\Http\Controllers\PayrollPeriodController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ShiftAssignmentController;
use App\Http\Controllers\ShiftBreakController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SocialSecurityAffiliationController;
use App\Http\Controllers\SocialSecurityConceptDefinitionController;
use App\Http\Controllers\SocialSecurityEntityController;
use App\Http\Controllers\SocialSecurityRuleVersionController;
use App\Http\Controllers\WorkScheduleTemplateController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::post('employees/{employee}/contracts', [EmploymentContractController::class, 'store'])->name('employees.contracts.store');
    Route::post('employees/{employee}/payroll-information', [PayrollInformationController::class, 'store'])->name('employees.payroll-information.store');

    Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
    Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
    Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
    Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');

    Route::get('schedules', [WorkScheduleTemplateController::class, 'index'])->name('schedules.index');
    Route::post('schedules', [WorkScheduleTemplateController::class, 'store'])->name('schedules.store');

    Route::get('labor-rules', [LaborRuleVersionController::class, 'index'])->name('labor-rules.index');
    Route::post('labor-rules/versions', [LaborRuleVersionController::class, 'store'])->name('labor-rules.versions.store');

    Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
    Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');

    Route::post('employees/{employee}/schedule', [EmployeeScheduleController::class, 'store'])->name('employees.schedule.store');
    Route::get('employees/{employee}/shifts', [ShiftController::class, 'index'])->name('employees.shifts.index');
    Route::post('employees/{employee}/shifts', [ShiftController::class, 'store'])->name('employees.shifts.store');
    Route::post('employees/{employee}/shifts/generate', [ShiftController::class, 'generate'])->name('employees.shifts.generate');
    Route::post('shifts/{shift}/assignment', [ShiftAssignmentController::class, 'update'])->name('shifts.assignment.update');
    Route::post('shifts/{shift}/breaks', [ShiftBreakController::class, 'store'])->name('shifts.breaks.store');

    Route::get('employees/{employee}/attendance', [AttendanceEventController::class, 'index'])->name('employees.attendance.index');
    Route::post('employees/{employee}/attendance/events', [AttendanceEventController::class, 'store'])->name('employees.attendance.events.store');
    Route::post('employees/{employee}/attendance/adjustments', [AttendanceAdjustmentController::class, 'store'])->name('employees.attendance.adjustments.store');
    Route::post('attendance/adjustments/{adjustment}/approve', [AttendanceAdjustmentController::class, 'approve'])->name('attendance.adjustments.approve');
    Route::post('attendance/adjustments/{adjustment}/reject', [AttendanceAdjustmentController::class, 'reject'])->name('attendance.adjustments.reject');

    Route::get('employees/{employee}/time-calculation', [AttendanceRecordController::class, 'index'])->name('employees.time-calculation.index');
    Route::post('employees/{employee}/time-calculation/recalculate', [AttendanceRecordController::class, 'recalculate'])->name('employees.time-calculation.recalculate');

    Route::get('employees/{employee}/leave-records', [LeaveRecordController::class, 'index'])->name('employees.leave-records.index');
    Route::post('employees/{employee}/leave-records', [LeaveRecordController::class, 'store'])->name('employees.leave-records.store');
    Route::post('leave-records/{record}/approve', [LeaveRecordController::class, 'approve'])->name('leave-records.approve');
    Route::post('leave-records/{record}/reject', [LeaveRecordController::class, 'reject'])->name('leave-records.reject');

    Route::get('employees/{employee}/overtime-records', [OvertimeRecordController::class, 'index'])->name('employees.overtime-records.index');
    Route::post('overtime-records/{record}/request', [OvertimeRecordController::class, 'request'])->name('overtime-records.request');
    Route::post('overtime-records/{record}/authorize', [OvertimeRecordController::class, 'authorize'])->name('overtime-records.authorize');
    Route::post('overtime-records/{record}/reject', [OvertimeRecordController::class, 'reject'])->name('overtime-records.reject');
    Route::post('overtime-records/{record}/mark-paid', [OvertimeRecordController::class, 'markPaid'])->name('overtime-records.mark-paid');

    Route::get('payroll/periods', [PayrollPeriodController::class, 'index'])->name('payroll.periods.index');
    Route::post('payroll/periods', [PayrollPeriodController::class, 'store'])->name('payroll.periods.store');
    Route::get('payroll/periods/{period}', [PayrollPeriodController::class, 'show'])->name('payroll.periods.show');
    Route::post('payroll/periods/{period}/calculate', [PayrollPeriodController::class, 'calculate'])->name('payroll.periods.calculate');
    Route::post('payroll/periods/{period}/approve', [PayrollPeriodController::class, 'approve'])->name('payroll.periods.approve');
    Route::post('payroll/periods/{period}/close', [PayrollPeriodController::class, 'close'])->name('payroll.periods.close');
    Route::post('payroll/periods/{period}/reopen', [PayrollPeriodController::class, 'reopen'])->name('payroll.periods.reopen');
    Route::post('payroll/entries/{entry}/adjustments', [PayrollAdjustmentController::class, 'store'])->name('payroll.entries.adjustments.store');

    Route::get('employees/{employee}/deduction-plans', [PayrollDeductionPlanController::class, 'index'])->name('employees.deduction-plans.index');
    Route::post('employees/{employee}/deduction-plans', [PayrollDeductionPlanController::class, 'store'])->name('employees.deduction-plans.store');

    Route::get('social-security/entities', [SocialSecurityEntityController::class, 'index'])->name('social-security.entities.index');
    Route::post('social-security/entities', [SocialSecurityEntityController::class, 'store'])->name('social-security.entities.store');
    Route::put('social-security/entities/{entity}', [SocialSecurityEntityController::class, 'update'])->name('social-security.entities.update');
    Route::delete('social-security/entities/{entity}', [SocialSecurityEntityController::class, 'destroy'])->name('social-security.entities.destroy');

    Route::get('social-security/concept-definitions', [SocialSecurityConceptDefinitionController::class, 'index'])->name('social-security.concept-definitions.index');
    Route::post('social-security/concept-definitions', [SocialSecurityConceptDefinitionController::class, 'store'])->name('social-security.concept-definitions.store');
    Route::put('social-security/concept-definitions/{concept}', [SocialSecurityConceptDefinitionController::class, 'update'])->name('social-security.concept-definitions.update');
    Route::delete('social-security/concept-definitions/{concept}', [SocialSecurityConceptDefinitionController::class, 'destroy'])->name('social-security.concept-definitions.destroy');

    Route::get('social-security/concept-definitions/{concept}/rule-versions', [SocialSecurityRuleVersionController::class, 'index'])->name('social-security.concept-definitions.rule-versions.index');
    Route::post('social-security/concept-definitions/{concept}/rule-versions', [SocialSecurityRuleVersionController::class, 'store'])->name('social-security.rule-versions.store');

    Route::get('employees/{employee}/social-security-affiliations', [SocialSecurityAffiliationController::class, 'index'])->name('employees.social-security-affiliations.index');
    Route::post('employees/{employee}/social-security-affiliations', [SocialSecurityAffiliationController::class, 'store'])->name('employees.social-security-affiliations.store');
});

require __DIR__.'/settings.php';
