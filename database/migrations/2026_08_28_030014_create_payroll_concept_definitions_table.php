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
        Schema::create('payroll_concept_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: null means a platform default catalog entry, not
            // scoped to any single company (same DIRECTO/GLOBAL pattern as
            // novelty_types/holidays).
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->string('calculation_method');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_concept_definitions');
    }
};
