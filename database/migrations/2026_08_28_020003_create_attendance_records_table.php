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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('planned_json');
            $table->json('worked_json');
            $table->unsignedInteger('ordinary_minutes');
            $table->unsignedInteger('overtime_candidate_minutes');
            $table->unsignedInteger('missing_minutes');
            // restrictOnDelete, not cascade: a rule version already used in a
            // past calculation must not silently disappear if someone tries
            // to delete it — the delete is blocked instead.
            $table->foreignUuid('rule_version_id')->constrained('labor_rule_versions')->restrictOnDelete();
            $table->timestamp('calculated_at');
            $table->timestamps();

            // Enables updateOrCreate()-based full regeneration: never two
            // records for the same employee+date (ADR-014, always
            // regenerate completely, never patch incrementally).
            $table->unique(['employee_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
