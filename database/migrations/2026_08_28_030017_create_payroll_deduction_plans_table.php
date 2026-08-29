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
        Schema::create('payroll_deduction_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('concept_id')->constrained('payroll_concept_definitions')->restrictOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->unsignedInteger('installments');
            // Not in the abbreviated .ai/05-DATABASE.md list, but needed so
            // the service never re-derives total_amount/installments every
            // period, which would accumulate rounding drift.
            $table->decimal('installment_amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_plans');
    }
};
