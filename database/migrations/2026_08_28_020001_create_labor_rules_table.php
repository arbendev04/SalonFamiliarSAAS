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
        Schema::create('labor_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: a null company_id represents a platform-wide
            // default rule rather than a company-specific override.
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rule_type');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'rule_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labor_rules');
    }
};
