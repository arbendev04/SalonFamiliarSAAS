<?php

use App\Http\Controllers\AttendanceEventController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeScheduleController;
use App\Http\Controllers\EmploymentContractController;
use App\Http\Controllers\PayrollInformationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ShiftAssignmentController;
use App\Http\Controllers\ShiftBreakController;
use App\Http\Controllers\ShiftController;
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

    Route::post('employees/{employee}/schedule', [EmployeeScheduleController::class, 'store'])->name('employees.schedule.store');
    Route::get('employees/{employee}/shifts', [ShiftController::class, 'index'])->name('employees.shifts.index');
    Route::post('employees/{employee}/shifts', [ShiftController::class, 'store'])->name('employees.shifts.store');
    Route::post('employees/{employee}/shifts/generate', [ShiftController::class, 'generate'])->name('employees.shifts.generate');
    Route::post('shifts/{shift}/assignment', [ShiftAssignmentController::class, 'update'])->name('shifts.assignment.update');
    Route::post('shifts/{shift}/breaks', [ShiftBreakController::class, 'store'])->name('shifts.breaks.store');

    Route::get('employees/{employee}/attendance', [AttendanceEventController::class, 'index'])->name('employees.attendance.index');
    Route::post('employees/{employee}/attendance/events', [AttendanceEventController::class, 'store'])->name('employees.attendance.events.store');
});

require __DIR__.'/settings.php';
