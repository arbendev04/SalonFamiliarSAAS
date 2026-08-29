<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_entry_lines', function (Blueprint $table) {
            // App\Services\Payroll\PayrollCalculationService::fixedDeductionLines()
            // already computes which PayrollDeductionPlan a deduction line came
            // from (`plan_id` in its returned array), but until this column
            // existed that information was silently discarded at persistence
            // time (calculateForEmployee() never wrote it anywhere) — leaving
            // App\Services\Payroll\PayrollPeriodService::close() with no way to
            // trace a period's deduction lines back to the plan whose
            // `remaining` must be decremented. Nullable because only deduction
            // lines sourced from a plan carry it — earning lines (base salary,
            // overtime) never do.
            $table->foreignUuid('deduction_plan_id')
                ->nullable()
                ->after('contract_id')
                ->constrained('payroll_deduction_plans')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_entry_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deduction_plan_id');
        });
    }
};
