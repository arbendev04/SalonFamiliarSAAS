<?php

use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmploymentContractController;
use App\Http\Controllers\PayrollInformationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::post('employees/{employee}/contracts', [EmploymentContractController::class, 'store'])->name('employees.contracts.store');
    Route::post('employees/{employee}/payroll-information', [PayrollInformationController::class, 'store'])->name('employees.payroll-information.store');
});

require __DIR__.'/settings.php';
