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
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Denormalized per ADR-006 even though this table is HEREDADO
            // from work_schedule_templates (defense in depth for tenant
            // scoping, see .ai/05-DATABASE.md).
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('template_id')->constrained('work_schedule_templates')->cascadeOnDelete();
            // 0 = Sunday .. 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
    }
};
