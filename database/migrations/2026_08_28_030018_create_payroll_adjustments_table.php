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
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->restrictOnDelete();
            // Not in the abbreviated .ai/05-DATABASE.md list, but needed
            // because applied_in_period_id alone can't distinguish between
            // the two ADR-026 correction paths.
            $table->string('mechanism');
            $table->json('original_value')->nullable();
            $table->json('corrected_value');
            $table->string('reason');
            // users.id is an integer PK (see 0001_01_01_000000_create_users_table.php),
            // unlike the uuid tables above — foreignId(), not foreignUuid().
            // Mirrors attendance_adjustments.requested_by exactly: not
            // nullable, cascadeOnDelete().
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('applied_in_period_id')->nullable()->constrained('payroll_periods')->restrictOnDelete();
            // payroll_adjustments is INSERT-only (ADR-026): no updated_at
            // column exists at all, same pattern as attendance_events and
            // time_calculation_runs — the row is never touched again after
            // creation.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
