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
        Schema::create('payroll_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            // Nullable: only null when status='blocked' (calculation could
            // not resolve a contract for the employee for this period).
            $table->foreignUuid('contract_id')->nullable()->constrained('employment_contracts')->restrictOnDelete();
            $table->string('status')->default('calculated');
            $table->decimal('gross_total', 12, 2)->default(0);
            $table->decimal('deductions_total', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
    }
};
