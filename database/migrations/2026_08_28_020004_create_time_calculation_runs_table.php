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
        Schema::create('time_calculation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignUuid('rule_version_id')->constrained('labor_rule_versions')->restrictOnDelete();
            $table->string('inputs_hash');
            // Not nullable: a row here is only ever written together with
            // the attendance_records row it traces, on a successful
            // calculation — never independently (.ai/09-TIME-CALCULATION.md,
            // Flujo 1).
            $table->foreignUuid('output_ref')->constrained('attendance_records')->cascadeOnDelete();
            // time_calculation_runs is INSERT-only, same reasoning and same
            // pattern as attendance_events: no updated_at column exists at
            // all, since a row is never touched again after creation.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_calculation_runs');
    }
};
