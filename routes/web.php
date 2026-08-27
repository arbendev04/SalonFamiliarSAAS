<?php

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeScheduleController;
use App\Http\Controllers\EmploymentContractController;
use App\Http\Controllers\PayrollInformationController;
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

    Route::get('schedules', [WorkScheduleTemplateController::class, 'index'])->name('schedules.index');
    Route::post('schedules', [WorkScheduleTemplateController::class, 'store'])->name('schedules.store');

    Route::post('employees/{employee}/schedule', [EmployeeScheduleController::class, 'store'])->name('employees.schedule.store');
    Route::get('employees/{employee}/shifts', [ShiftController::class, 'index'])->name('employees.shifts.index');
    Route::post('employees/{employee}/shifts', [ShiftController::class, 'store'])->name('employees.shifts.store');
    Route::post('employees/{employee}/shifts/generate', [ShiftController::class, 'generate'])->name('employees.shifts.generate');
    Route::post('shifts/{shift}/assignment', [ShiftAssignmentController::class, 'update'])->name('shifts.assignment.update');
    Route::post('shifts/{shift}/breaks', [ShiftBreakController::class, 'store'])->name('shifts.breaks.store');
});

require __DIR__.'/settings.php';
