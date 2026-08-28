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
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->dateTime('event_datetime');
            $table->string('source');
            $table->foreignUuid('device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->json('metadata')->nullable();
            // attendance_events is INSERT-only (.ai/07-ATTENDANCE.md,
            // ADR-003): no updated_at column exists at all, since the row
            // is never touched again after creation.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'event_datetime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_events');
    }
};
