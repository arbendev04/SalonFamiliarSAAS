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
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            // Null when the shift was created manually, not generated from a
            // template (.ai/04-DOMAIN-MODEL.md).
            $table->foreignUuid('template_id')->nullable()->constrained('work_schedule_templates')->nullOnDelete();
            $table->date('date');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            // Descriptive classification only (e.g. "regular", "coverage");
            // crosses_midnight is what actually drives calculation logic.
            $table->string('type')->default('regular');
            $table->boolean('crosses_midnight')->default(false);
            $table->string('source')->default('manual');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
