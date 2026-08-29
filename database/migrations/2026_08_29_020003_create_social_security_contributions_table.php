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
        Schema::create('social_security_contributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payroll_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('entity_id')->constrained('social_security_entities')->restrictOnDelete();
            $table->foreignUuid('concept_id')->constrained('social_security_concept_definitions')->restrictOnDelete();
            // Distinguishes which sub-range a row corresponds to when the
            // employee's affiliation changes mid-period.
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('base_amount', 12, 2);
            $table->decimal('employee_amount', 12, 2);
            $table->decimal('employer_amount', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_security_contributions');
    }
};
